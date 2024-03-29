<?php
/**
 * Plugin Name: Gigaom OpenCalais Autotagger
 * Plugin URI:
 * Description:
 * Version: 0.1
 * Author: Adam Backstrom for Gigaom
 * Author URI: http://sixohthree.com/
 * License: GPL2
 */

class GO_OpenCalais_AutoTagger
{

	protected $threshold = 0.29;

	public function __construct()
	{
		$this->config = go_opencalais()->admin()->config;

		add_action( 'wp_ajax_oc_autotag', array( $this, 'autotag_batch' ) );
		add_action( 'wp_ajax_oc_autotag_update', array( $this, 'update' ) );
		add_action( 'go_oc_content', array( $this, 'go_oc_content' ), 5, 3 );
	}//end __construct

	public function autotag_batch()
	{
		global $post, $wp_version, $wpdb;

		if ( ! current_user_can( 'manage_options') )
		{
			die( 'no access' );
		}//end if

		// any updates to the default threshold?
		$this->threshold = apply_filters( 'go_oc_autotag_threshold', $this->threshold );

		$posts_per_page = isset( $_REQUEST['posts_per_page'] ) ? (int) $_REQUEST['posts_per_page'] : $this->config['autotagger']['per_page'];

		// sanity check
		if ( $posts_per_page > 20 )
		{
			$posts_per_page = 20;
		}//end if

		if ( version_compare( $wp_version, '3.1', '>=' ) )
		{
			$tax_query = array(
				array(
					'taxonomy' => $this->config['autotagger']['taxonomy'],
					'field'    => 'slug',
					'terms'    => $this->config['autotagger']['term'],
					'operator' => 'NOT IN',
				),
			);

			$args = array(
				'post_type'      => 'post',
				'posts_per_page' => $posts_per_page,
				'tax_query'      => $tax_query,
				'orderby'        => 'ID',
				'order'          => 'DESC',
				'fields'         => 'ids',
			);

			$query = new WP_Query( $args );
			$posts = $query->posts;
		}//end if
		else
		{
			$term = get_term_by( 'slug', $this->config['autotagger']['term'], $this->config['autotagger']['taxonomy'] );
			$term_taxonomy_id = $term ? $term->term_taxonomy_id : -1;
			$posts = $wpdb->get_col( $sql = $wpdb->prepare( "
				SELECT p.ID
				FROM $wpdb->posts p
				LEFT JOIN $wpdb->term_relationships tr ON tr.object_id = p.ID AND tr.term_taxonomy_id = %d
				WHERE 1=1
				AND tr.object_id IS NULL
				AND p.post_type = 'post'
				AND p.post_status = 'publish'
				ORDER BY p.ID DESC
				LIMIT %d", $term_taxonomy_id, $posts_per_page ) );
		}//end else

		// subsequent ajax loads
		if ( ! isset( $_REQUEST['more'] ) )
		{
			echo '<h1>OpenCalais Bulk Auto-tagger</h1>';
		}//end if

		foreach( $posts as $post )
		{
			$post = get_post( $post );

			echo '<hr>';
			echo '<h2><a href="', esc_attr( get_edit_post_link() ), '">', get_the_title(), '</a> (#', get_the_ID(), ')</h2>';

			$taxes = $this->autotag_post( $post );
			if( is_wp_error( $taxes ) )
			{
				echo "<span style='color:red'>ERROR:</span> ", $taxes->get_error_message();
				continue;
			}//end if

			echo '<ul>';

			foreach( $taxes as $tax => $terms )
			{
				$first = true;
				foreach( $terms as $term )
				{
					if( $first )
					{
						echo '<li>';
						$local_tax = ( isset( $term['local_tax'] ) && $term['local_tax'] ) ? $term['local_tax'] : false;
						$color     = $local_tax ? 'green' : 'red';
						echo "<span style='font-weight:bold;color:$color'>", esc_html( $tax ), ( $local_tax ? " ($local_tax)" : '' ),
							'</span> ';
						$first = false;
					}//end if

					if ( $term['usable'] )
					{
						$term['term'] = '<strong>' . esc_html( $term['term'] ) . ' <small>(' . esc_html( $term['rel'] ) . ')</small> </strong>';
					}//end if
					else
					{
						$term['term'] = '<small>' . esc_html( $term['term'] ) . ' <small>(' . esc_html( $term['rel'] ) . ')</small></small>';
					}//end else

					echo esc_html( $term['term'] ), '; ';
				}//end foreach
				echo '</li>';
			}//end foreach

			echo '</ul>';
		}//end foreach

		if ( count($posts) > 0 )
		{
			// first time through, load jquery and create reload function
			if ( ! isset( $_REQUEST['more'] ) )
			{
				?>
				<div id="more"><a href="">Loading more in 5 seconds&hellip;</a></div>
				<script type="text/javascript">
				jQuery( function() {
					var do_oc_refresh = function() {
						jQuery.get( document.location.href, {'more':1}, function( data, ts, xhr ) {
							jQuery('#more').before( data );
							if( 'done' == data ) {
								jQuery('#more').remove();
							} else {
								setTimeout( do_oc_refresh, 5000 );
							}
						});
					};
					setTimeout( do_oc_refresh, 5000 );
				});
				</script>
				<?php
			}//end if
		}//end if
		else
		{
			echo 'done';
		}//end else

		die;
	}//end autotag_batch

	protected function autotag_post( $post )
	{
		$enrich = new go_opencalais_enrich( $post );

		$error = $enrich->enrich();
		if ( is_wp_error( $error ) )
		{
			// FIXME: this is imperfect. what if the content was empty
			// as the result of a bogus filter, or some other error?
			// do we need another tag for skipped posts, or does it not
			// matter? (I'm getting this error for an auto-draft, and its
			// causing the queue to have a recurring item that never
			// gets tagged.)
			$this->mark_autotagged( $post );

			return $error;
		}//end if

		$error = $enrich->save();
		if ( is_wp_error( $error ) )
		{
			return $error;
		}//end if

		// Array of all incoming suggested tags, regardless of relevancy
		// or taxonomy.
		//
		//     $taxes[ $tax ][ $term ] = array( 'rel' => N, 'rel_orig' => N )
		$taxes = array();

		// Terms to use, by local taxonomy.
		//
		//     $valid_terms[ $local_tax ] = array( $term [ , $term, ... ] )
		$valid_terms = array();

		foreach( $enrich->response as $obj )
		{
			if ( ! isset( $obj->relevance ) )
			{
				continue;
			}//end if

			$rel       = $obj->relevance;
			$rel_orig  = $obj->_go_orig_relevance;
			$type      = $obj->_type;
			$term      = $obj->name;
			$local_tax = null;
			$usable    = $rel > $this->threshold;

			// does this type map to a local taxonomy?
			if ( isset( $this->config['mapping'][ $type ] ) )
			{
				$local_tax = $this->config['mapping'][ $type ];
			}//end if

			if ( ! isset( $taxes[ $type ] ) )
			{
				$taxes[ $type ] = array();
			}//end if

			$taxes[ $type ][ $term ] = compact( 'rel', 'rel_orig', 'local_tax', 'usable', 'term' );

			if ( $usable && $local_tax )
			{
				if( ! isset( $valid_terms[ $local_tax ] ) )
				{
					$valid_terms[ $local_tax ] = array();
				}//end if

				$valid_terms[ $local_tax ][] = $term;
			}//end if
		}//end foreach

		$valid_terms = apply_filters( 'go_oc_autotag_terms', $valid_terms, $taxes );

		// append terms to the post
		foreach( $valid_terms as $tax => $terms )
		{
			wp_set_object_terms( $post->ID, $terms, $tax, true );
		}//end foreach

		$this->mark_autotagged( $post );

		return $taxes;
	}//end autotag_post

	public function go_oc_content( $content, $post_id, $post )
	{
		$term_list = get_the_term_list( $post_id, 'post_tag', '', '; ', '' );

		if ( ! empty( $term_list ) && ! is_wp_error( $term_list ) )
		{
			$content = $content . "\n  \n" . (string) strip_tags( $term_list );
		}//end if

		return $post->post_title ."\n  \n". $post->post_excerpt ."\n  \n". $content;
	}//end go_oc_content

	public function update()
	{
		if ( ! current_user_can( 'manage_options' ) )
		{
			die( 'no access' );
		}//end if

		if ( ! term_exists( $this->config['autotagger']['term'], $this->config['autotagger']['taxonomy'] ) )
		{
			wp_insert_term( $this->config['autotagger']['term'], $this->config['autotagger']['taxonomy'] );
			echo 'updated term ';
		}//end if
		else
		{
			echo 'no updates needed ';
		}// end else
	}//end update

	protected function mark_autotagged( $post )
	{
		// mark this record as tagged
		return wp_set_object_terms( $post->ID, $this->config['autotagger']['term'], $this->config['autotagger']['taxonomy'], true );
	}//end mark_autotagged
}//end class
