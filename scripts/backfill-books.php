<?php
/**
 * Backfill book meta from Hardcover GraphQL API.
 *
 * Usage:
 *   scp scripts/backfill-books.php homeip:/tmp/backfill-books.php
 *   ssh homeip bash << 'EOF'
 *   docker run --rm \
 *     --user 65532 \
 *     -v wp-possee_wp_data:/var/www/html \
 *     -v /storage/Docker/wp-possee/mu-plugins:/var/www/html/wp-content/mu-plugins \
 *     --network db \
 *     -e WORDPRESS_DB_HOST=mariadb \
 *     -e WORDPRESS_DB_USER=wordpress \
 *     -e WORDPRESS_DB_PASSWORD=${MYSQL_PASSWORD} \
 *     -e WORDPRESS_DB_NAME=wordpress \
 *     wordpress:cli-php8.3 wp --allow-root eval-file /tmp/backfill-books.php <HARDCOVER_API_KEY>
 *   EOF
 *
 * Get your API key from https://hardcover.app/account/api
 */

$raw_key = $argv[1] ?? getenv( 'HARDCOVER_API_KEY' ) ?? '';
// Fallback: read from key file created by scp.
if ( ! $raw_key && file_exists( '/tmp/hc_key.txt' ) ) {
	$raw_key = trim( file_get_contents( '/tmp/hc_key.txt' ) );
}
if ( ! $raw_key ) {
	fwrite( STDERR, "Usage: wp eval-file backfill-books.php <HARDCOVER_API_KEY>\n" );
	exit( 1 );
}
// Strip "Bearer " prefix if user already included it (the script adds it).
$api_key = preg_replace( '/^Bearer\s+/i', '', $raw_key );

// ── GraphQL query (same shape as the n8n bulk import) ────────

$query = <<<'GRAPHQL'
query {
  me {
    user_books(
      where: { status_id: { _eq: 3 } }
      order_by: { updated_at: asc }
      limit: 1000
    ) {
      id
      updated_at
      rating
      book {
        title
        pages
        release_year
        book_category_id
        cached_tags(path: "Genre")
        contributions {
          author { name }
        }
        editions(limit: 1, order_by: { users_count: desc }) {
          isbn_13
          isbn_10
          image { url }
        }
        book_series {
          series { name books_count is_completed }
          position
        }
      }
    }
  }
}
GRAPHQL;

// ── Fetch from Hardcover ─────────────────────────────────────

$response = wp_remote_post( 'https://api.hardcover.app/v1/graphql', array(
	'headers' => array(
		'Authorization' => 'Bearer ' . $api_key,
		'Content-Type'  => 'application/json',
	),
	'body'    => json_encode( array( 'query' => $query ) ),
	'timeout' => 30,
) );

if ( is_wp_error( $response ) ) {
	fwrite( STDERR, "HTTP error: " . $response->get_error_message() . "\n" );
	exit( 1 );
}

$status = wp_remote_retrieve_response_code( $response );
if ( $status !== 200 ) {
	fwrite( STDERR, "HTTP $status: " . wp_remote_retrieve_body( $response ) . "\n" );
	exit( 1 );
}

$body  = json_decode( wp_remote_retrieve_body( $response ), true );
$books = $body['data']['me'][0]['user_books'] ?? array();

if ( empty( $books ) ) {
	fwrite( STDOUT, "No finished books found on Hardcover.\n" );
	exit( 0 );
}

fwrite( STDOUT, "Fetched " . count( $books ) . " books from Hardcover.\n" );

// ── Process each book ────────────────────────────────────────

$updated  = 0;
$skipped  = 0;
$not_found = 0;

foreach ( $books as $ub ) {
	$book   = $ub['book'];
	$title  = $book['title'] ?? 'Unknown';

	// Derive ISBN from first edition.
	$edition = $book['editions'][0] ?? array();
	$isbn    = $edition['isbn_13'] ?? $edition['isbn_10'] ?? null;
	if ( ! $isbn ) {
		fwrite( STDOUT, "  SKIP {$title}: no ISBN\n" );
		$skipped++;
		continue;
	}

	// Find WP post by ISBN.
	$posts = get_posts( array(
		'post_type'      => 'book',
		'post_status'    => 'any',
		'meta_key'       => 'isbn',
		'meta_value'     => $isbn,
		'fields'         => 'ids',
		'posts_per_page' => 1,
		'no_found_rows'  => true,
	) );

	if ( empty( $posts ) ) {
		fwrite( STDOUT, "  NOT FOUND {$title} (isbn:{$isbn})\n" );
		$not_found++;
		continue;
	}

	$post_id = $posts[0];

	// ── Build meta updates ───────────────────────────────────

	$meta = array();

	// Series info.
	$book_series = $book['book_series'][0] ?? null;
	if ( $book_series && ! empty( $book_series['series']['name'] ) ) {
		$meta['mf2_book-series'] = $book_series['series']['name'];
		if ( isset( $book_series['position'] ) && $book_series['position'] !== null ) {
			$meta['mf2_book-series-position'] = (string) $book_series['position'];
		}
		if ( isset( $book_series['series']['books_count'] ) && $book_series['series']['books_count'] !== null ) {
			$meta['mf2_book-series-count'] = (int) $book_series['series']['books_count'];
		}
		if ( isset( $book_series['series']['is_completed'] ) && $book_series['series']['is_completed'] !== null ) {
			$meta['mf2_book-series-completed'] = $book_series['series']['is_completed'] ? '1' : '0';
		}
	}

	// Pages.
	if ( isset( $book['pages'] ) && $book['pages'] !== null ) {
		$meta['mf2_book-pages'] = (int) $book['pages'];
	}

	// Release year.
	if ( isset( $book['release_year'] ) && $book['release_year'] !== null ) {
		$meta['mf2_book-release-year'] = (int) $book['release_year'];
	}

	// Category ID.
	if ( isset( $book['book_category_id'] ) && $book['book_category_id'] !== null ) {
		$meta['mf2_book-category-id'] = (int) $book['book_category_id'];
	}

	// Cover URL.
	if ( ! empty( $edition['image']['url'] ) ) {
		$meta['mf2_book-cover-url'] = $edition['image']['url'];
	}

	// Genres.
	if ( ! empty( $book['cached_tags'] ) ) {
		$genres = array();
		foreach ( $book['cached_tags'] as $tag ) {
			if ( ( $tag['category'] ?? '' ) === 'Genre' ) {
				$genres[] = $tag['tag'];
			}
		}
		if ( $genres ) {
			$meta['mf2_book-genres'] = $genres;
		}
	}

	// ── Apply meta ───────────────────────────────────────────

	foreach ( $meta as $key => $value ) {
		update_post_meta( $post_id, $key, $value );
	}

	fwrite( STDOUT, "  OK {$title} (ID {$post_id}): " . count( $meta ) . " fields\n" );
	$updated++;
}

// ── Summary ──────────────────────────────────────────────────

fwrite( STDOUT, "\nDone. {$updated} updated, {$skipped} skipped (no ISBN), {$not_found} not found in WordPress.\n" );
