<?php

class MTools {

	private $fields = array();
	private $posttype = 'post';
	private $posts = array();

	function __construct() {
		add_action( 'admin_init', array($this,'mt_admin_init') );
		add_action( 'admin_menu', array($this,'mt_admin_menu') );
		add_action( 'wp_loaded', array( &$this, 'mt_loaded' ));

		$this->posttype = 'post';
		if (isset($_GET['post_type'])) {
			$this->posttype = $_GET['post_type'];
		}
	}

	function mt_admin_init() {
		global $pagenow;

		// Post List
		if ( $pagenow=='edit.php') {

			// ACF Fields
			if ( is_plugin_active( 'advanced-custom-fields-pro/acf.php' ) ) {

				$fg = acf_get_field_groups();
				foreach ( $fg as $g ) {
					if ( acf_get_field_group_visibility( $g, array( 'post_type' => $this->posttype ) ) ) {
						$this->fields = array_merge( acf_get_fields( $g ), $this->fields );
					}
				}

				foreach ( $this->fields as $f ) {
					if ( $f['type'] == 'post_object' ) {
						wp_reset_query();
						$args  = acf_parse_args( $args,
							array(
								'posts_per_page'         => - 1,
								'paged'                  => 0,
								'post_type'              => $f['post_type'],
								'orderby'                => 'menu_order title',
								'order'                  => 'ASC',
								'post_status'            => 'any',
								'suppress_filters'       => false,
								'update_post_meta_cache' => false,
							) );
						$posts = get_posts( $args );
						foreach ( $posts as $p ) {
							$this->posts[ $f['name'] ][ $p->ID ] = $p->post_title;
						}
						wp_reset_query();
						$args=null;
					}
				}

				if ( count( $this->fields ) ) {
					add_filter('manage_'.$this->posttype.'_posts_columns',array(&$this,'mt_table_head'));
					add_action('manage_'.$this->posttype.'_posts_custom_column',array(&$this,'mt_table_content'),10,2);
					add_filter('manage_edit-'.$this->posttype.'_sortable_columns',array(&$this,'mt_table_sort'));
					add_action('restrict_manage_posts', array( $this, 'mt_filter_admin_list' ) );
					add_filter('parse_query', array( $this, 'mt_posts_filter' ) );
				}
			}

			// ID Field
			$pts = get_post_types();
			foreach ($pts as $pt) {
				add_filter('manage_'.$pt.'_posts_columns',array($this,'mt_id_head'));
				add_action('manage_'.$pt.'_posts_custom_column', array($this,'mt_id_content'), 10, 2 );
				add_filter('manage_edit-'.$pt.'_sortable_columns', array($this,'mt_id_sort') );
			}

			// ID Field Posts
			add_filter('manage_post_posts_columns',array($this,'mt_id_head'));
			add_action('manage_post_posts_custom_column', array($this,'mt_id_content'), 10, 2 );
			add_filter('manage_edit-post_sortable_columns', array($this,'mt_id_sort') );

			// ID Field Pages
			add_filter('manage_pages_posts_columns',array($this,'mt_id_head'));
			add_action('manage_pages_posts_custom_column', array($this,'mt_id_content'), 10, 2 );
			add_filter('manage_edit-pages_sortable_columns', array($this,'mt_id_sort') );
		}

		// Post Edit
		if (is_admin() && $pagenow=='post.php') {
			add_action('add_meta_boxes', array($this,'mt_meta_boxes'));
		}

		// Row Actions
		add_filter('post_row_actions',array(&$this,'mt_row_actions'),10,2);
		add_filter('page_row_actions',array(&$this,'mt_row_actions'),10,2);

		// User ID
		add_filter('manage_users_columns', array(&$this,'mt_user_id_column'));
		add_action('manage_users_custom_column',  array(&$this,'mt_user_id_column_content'), 10, 3);

		// Styles
		add_action('admin_head', array($this,'mt_styles'));
	}

	function mt_styles() {
		echo '<style type="text/css">
			  .widefat .column-post_id, .widefat .column-user_id {
					width: 5em;
					vertical-align: top;
				}
			 </style>';
	}

	function mt_admin_menu() {
		add_menu_page( 'MTools', 'MTools', 'manage_options', 'mtools', array($this,'mt_admin'));
	}

	function mt_admin() {
		$type = $this->posttype;

		$fg = acf_get_field_groups();

		foreach ($fg as $g) {
			echo '<h3>'.$g['title'].'</h3>';


			$fields = acf_get_fields($g);
			foreach ($fields as $f) {
				echo '<p>';
				echo '<strong>Field: '.$f['label'].' ('.$f['name']. ') ['.$f['type'].']</strong><br />';
				echo print_r($f,true);
				echo '</p>';
			}


		}
	}

	function mt_loaded() {

		// Clone Post
		// From Clone Posts, http://wordpress.org/extend/plugins/clone-posts/, by Lukasz Kostrzewa, Licsnse GPL v2
		if ( isset($_GET['action']) && $_GET['action'] == "mt-clone-single") {
			$post_id = (int) $_GET['post'];

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				wp_die( __( 'You are not allowed to clone this post.' ) );
			}

			if ( ! $this->mt_clone_single_post( $post_id ) ) {
				wp_die( __( 'Error cloning post.' ) );
			}

			$sendback = remove_query_arg( array( 'cloned', 'untrashed', 'deleted', 'ids' ), $_GET['redirect'] );
			if ( ! $sendback ) {
				$sendback = admin_url( "edit.php?post_type=$this->posttype" );
			}

			$sendback = add_query_arg( array( 'cloned' => 1 ), $sendback );
			$sendback = remove_query_arg( array( 'action', 'action2', 'tags_input', 'post_author', 'comment_status', 'ping_status', '_status', 'post', 'bulk_edit','post_view' ), $sendback );
			wp_redirect( $sendback );
			exit();
		}
	}

	function mt_row_actions( $actions, $post ) {

		// Clone Post, from Clone Posts: http://wordpress.org/extend/plugins/clone-posts/ by Lukasz Kostrzewa License GPL v2
		$url = remove_query_arg( array( 'cloned', 'untrashed', 'deleted', 'ids' ), "" );
		if ( ! $url ) { $url = admin_url( "?post_type=$this->posttype" );}

		$url = remove_query_arg( array( 'action', 'action2', 'tags_input', 'post_author','comment_status', 'ping_status', '_status',  'post', 'bulk_edit', 'post_view'), $url );
		$url = add_query_arg( array( 'action' => 'mt-clone-single', 'post' => $post->ID, 'redirect' => $_SERVER['REQUEST_URI'] ), $url );

		$actions['duplicate'] =  '<a href=\''.$url.'\'>'.__('Duplicate').'</a>';

		return $actions;
	}

	function mt_id_head( $columns ) {
		$columns['post_id']  = 'ID';
		return $columns;
	}

	function mt_id_content( $column_name, $post_id ) {
		if( $column_name == 'post_id' ) { echo $post_id; }
	}

	function mt_id_sort($columns) {
		$columns['post_id'] = 'post_id';
		return $columns;
	}

	function mt_table_head( $columns ) {
		foreach ($this->fields as $f) {
			if ($f['type'] == 'text' || $f['type'] == 'url' || $f['type'] == 'radio' || $f['type'] == 'post_object' || $f['type'] == 'select' ) {
				$columns[$f['name']] = $f['label'];
			}
		}
		return $columns;
	}

	function mt_table_content( $column_name, $post_id ) {
		foreach ($this->fields as $f) {
			if( $column_name == $f['name']) {
				if ($f['type'] == 'text' || $f['type'] == 'url') {
					$value = get_field($f['name'], $post_id);
					echo $value;
				}
				if( $f['type'] == 'radio' || ($f['type'] == 'select' && !$f['multiple'])) {
					$value = get_field($f['name'], $post_id);
					echo $f['choices'][$value];
				}
				if( $f['type'] == 'select' && $f['multiple'] ) {
					$values = get_field($f['name'], $post_id);
					$answers = array();
					if (count($values) && $values) {
						foreach($values as $a) {
							$answers[] = $f['choices'][$a];
						}
						echo implode(', ',$answers);
					}
				}
				if ($f['type'] == 'post_object' && !$f['multiple']) {
					$value = get_field($f['name'], $post_id);
					if (!is_object($value)) echo $this->posts[$f['name']][$value];
					else echo $value->post_title;
				}
				if ($f['type'] == 'post_object' && $f['multiple']) {
					$values = get_field($f['name'], $post_id);
					$answers = array();
					if (count($values) && $values) {
						foreach($values as $value) {
							if (!is_object($value)) $answers[] = acf_get_post_title($value);
							else $answers[] = $value->post_title;
						}
					}
					echo implode(', ',$answers);
				}
			}
		}


	}

	function mt_table_sort( $columns ) {
		foreach ($this->fields as $f) {
			if ($f['type'] == 'text' || $f['type'] == 'url' || $f['type'] == 'radio' || ($f['type'] == 'select' && !$f['multiple']) ) {
				$columns[$f['name']] = $f['name'];
			}
		}
		return $columns;
	}

	function mt_filter_admin_list(){
		$type = 'post';
		if (isset($_GET['post_type'])) {
			$type = $_GET['post_type'];
		}

		//only add filter to post type you want
		if ($this->posttype == $type){
			foreach ($this->fields as $f) {
				$fn = 'admin_filter_'.$type.'_'.$f['name'];
				if($f['type'] == 'radio' || ($f['type'] == 'select' && !$f['multiple'])) {
					echo '<select name="'.$fn.'">';
					echo '<option value="">All '.$f['label'].'</option>';
					$current_v = isset($_GET[$fn])? $_GET[$fn]:'';
					foreach ($f['choices'] as $value => $label) {
						echo '<option value="'.$value.'"';
						if ($value == $current_v) echo ' selected="selected"';
						echo '>'.$label.'</option>';
					}
					echo '</select>';
				}

				if ($f['type'] == 'post_object' && !$f['multiple']) {

					echo '<select name="'.$fn.'">';
					echo '<option value="">All '.$f['label'].'</option>';
					$current_v = isset($_GET[$fn])? $_GET[$fn]:'';
					foreach ($this->posts[$f['name']] as $value=>$label) {
						echo '<option value="'.$value.'"';
						if ($value == $current_v) echo ' selected="selected"';
						echo '>'.$label.'</option>';
					}
					echo '</select>';
				}

			}
		}
	}

	function mt_posts_filter( $query ){
		global $pagenow;
		$type = 'post';
		if (isset($_GET['post_type'])) {
			$type = $_GET['post_type'];
		}

		if ($this->posttype == $type){
			foreach ($this->fields as $f) {
				$fn = 'admin_filter_'.$type.'_'.$f['name'];
				if(($f['type'] == 'radio' || ($f['type'] == 'select' && !$f['multiple']) || ($f['type'] == 'post_object' && !$f['multiple'])) && isset($_GET[$fn]) && $_GET[$fn] != '') {
					$query->query_vars['meta_key'] = $f['name'];
					$query->query_vars['meta_value'] = $_GET[$fn];
				}
			}
		}
	}

	function cf_post_id() {
		global $post;

		// Get the data
		$id = $post->ID;

		// Echo out the field
		echo '<strong>ID:</strong> '.$id;
	}

	function mt_meta_boxes() {
		add_meta_box('mtools_id', 'Info', array($this,'cf_post_id'), null, 'side', 'high');
	}

	function mt_clone_single_post( $id ) {
		// From Clone Posts, http://wordpress.org/extend/plugins/clone-posts/, by Lukasz Kostrzewa, License GPL v2

		$p = get_post( $id );
		if ($p == null) return false;

		$newpost = array(
			'post_name' => $p->post_name,
			'post_type' => $p->post_type,
			'ping_status' => $p->ping_status,
			'post_parent' => $p->post_parent,
			'menu_order' => $p->menu_order,
			'post_password' => $p->post_password,
			'post_excerpt' => $p->post_excerpt,
			'comment_status' => $p->comment_status,
			'post_title' => $p->post_title . __('- copy'),
			'post_content' => $p->post_content,
			'post_author' => $p->post_author,
			'to_ping' => $p->to_ping,
			'pinged' => $p->pinged,
			'post_content_filtered' => $p->post_content_filtered,
			'post_category' => $p->post_category,
			'tags_input' => $p->tags_input,
			'tax_input' => $p->tax_input,
			'page_template' => $p->page_template
		);
		$newid = wp_insert_post($newpost);

		$format = get_post_format( $id );
		set_post_format($newid, $format);

		// From Duplicate Post, https://wordpress.org/plugins/duplicate-post/, by Enrico Battocchi, License GPL v2
		// MetaKeys
		$meta_keys = get_post_custom_keys($id);
		if (!empty($meta_keys)) {

			foreach ($meta_keys as $meta_key) {
				$meta_values = get_post_custom_values($meta_key, $id);
				foreach ($meta_values as $meta_value) {
					$meta_value = maybe_unserialize($meta_value);
					add_post_meta($newid, $meta_key, $meta_value);
				}
			}
		}

		// From Duplicate Post, https://wordpress.org/plugins/duplicate-post/, by Enrico Battocchi, License GPL v2
		// Taxinomies
		global $wpdb;
		if (isset($wpdb->terms)) {
			// Clear default category (added by wp_insert_post)
			wp_set_object_terms( $newid, NULL, 'category' );

			$taxonomies = get_object_taxonomies($p->post_type);
			foreach ($taxonomies as $taxonomy) {
				$post_terms = wp_get_object_terms($id, $taxonomy, array( 'orderby' => 'term_order' ));
				$terms = array();
				for ($i=0; $i<count($post_terms); $i++) {
					$terms[] = $post_terms[$i]->slug;
				}
				wp_set_object_terms($newid, $terms, $taxonomy);
			}
		}

		return true;
	}

	function mt_user_id_column($columns) {
		$columns['user_id'] = 'ID';
		return $columns;
	}

	function mt_user_id_column_content($value, $column_name, $user_id) {
		$user = get_userdata( $user_id );
		if ( 'user_id' == $column_name )
			return $user_id;
		return $value;
	}
}