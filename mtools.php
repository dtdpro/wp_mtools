<?php

class MTools {

	private $fields = array();
	private $posttype = 'post';
	private $posts = array();

	function __construct() {
		if (is_admin()) {
			add_action( 'admin_init', array( $this, 'mt_admin_init' ) );
			add_action( 'admin_menu', array( $this, 'mt_admin_menu' ) );
			add_action( 'wp_loaded', array( &$this, 'mt_loaded' ) );
			add_action( 'acf/include_field_types', array( $this, 'mt_acf_gforms_field' ) );
			add_filter( 'plugin_action_links', array( $this, 'mt_plugin_actions' ), 10, 2 );

			$this->posttype = 'post';
			if ( isset( $_GET['post_type'] ) ) {
				$this->posttype = $_GET['post_type'];
			}
		} else {
			add_action( 'template_redirect', array( &$this, 'mt_template_redirect' ) );
			//add_action( 'pre_get_posts', array( &$this, 'mt_show_allowed_posts') );
        }
	}

	function mt_activate() {
		$opts['show_column_fi']=true;
		$opts['show_column_pid']=true;
		$opts['show_column_uid']=true;
		$opts['show_restricted']=false;
		add_option('wp_mtools', $opts);
	}

	function mt_deactivate() {
		delete_option('wp_mtools');
	}

	function mt_admin_init() {
		register_setting( 'wp_mtools', 'wp_mtools' );

		$options = get_option( 'wp_mtools' );

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

			// ID & Featured Image in post list
			$pts = get_post_types();
			foreach ($pts as $pt) {
				if ($options['show_column_pid']) {
					add_filter('manage_'.$pt.'_posts_columns',array($this,'mt_id_head'));
					add_action('manage_'.$pt.'_posts_custom_column', array($this,'mt_id_content'), 10, 2 );
					add_filter('manage_edit-'.$pt.'_sortable_columns', array($this,'mt_id_sort') );
				}
				if (post_type_supports( $pt,'thumbnail') && $options['show_column_fi']) {
					add_filter( 'manage_' . $pt . '_posts_columns', array( $this, 'mt_fi_head' ) );
					add_action( 'manage_' . $pt . '_posts_custom_column', array( $this, 'mt_fi_content' ), 10, 2 );
				}
			}
		}

		// Post Meta Box
		if (is_admin() && ($pagenow=='post.php' || $pagenow=='post-new.php')) {
			add_action('add_meta_boxes', array($this,'mt_meta_boxes'));
			add_action( 'save_post', array( &$this, 'mt_save_post' ), 10, 2 );
		}

		// Row Actions
		add_filter('post_row_actions',array(&$this,'mt_row_actions'),10,2);
		add_filter('page_row_actions',array(&$this,'mt_row_actions'),10,2);

		// User ID
		if ($options['show_column_uid']) {
			add_filter( 'manage_users_columns', array( &$this, 'mt_user_id_column' ) );
			add_action( 'manage_users_custom_column', array( &$this, 'mt_user_id_column_content' ), 10, 3 );
		}

		// Styles
		add_action('admin_head', array($this,'mt_styles'));
	}

	function mt_styles() {
		echo '<style type="text/css">
			  .widefat .column-post_id, .widefat .column-user_id {
					width: 4em;
					vertical-align: top;
				}
			  ..widefat .column-featured_image {
					width: 10em;
					vertical-align: top;
				}
			 </style>';
	}

	function mt_plugin_actions($action_links,$plugin_file){
		if($plugin_file=='wp_mtools/wp_mtools.php'){
			$wp_debug_link = '<a href="admin.php?page=mtools_info">Info</a>';
			array_unshift($action_links,$wp_debug_link);
			$wp_settings_link = '<a href="options-general.php?page=mtools_settings">' . __("Settings") . '</a>';
			array_unshift($action_links,$wp_settings_link);
		}
		return $action_links;
	}

	function mt_admin_menu() {
		add_menu_page( 'MTools', 'MTools', 'manage_options', 'mtools', array($this,'mt_admin_welcome'));
		add_submenu_page( 'mtools', 'MTools System Info', 'System Info', 'manage_options', 'mtools_info',array($this,'mt_admin_info') );
		if(function_exists('phpinfo')) add_submenu_page( 'mtools', 'MTools PHPInfo', 'PHPInfo', 'manage_options', 'mtools_phpinfo',array($this,'mt_admin_phpinfo') );
		add_submenu_page( 'mtools', 'MTools Cron', 'Cron', 'manage_options', 'mtools_cron',array($this,'mt_admin_cron') );
		add_submenu_page( 'mtools', 'MTools Post Types', 'Post Types', 'manage_options', 'mtools_posttypes',array($this,'mt_admin_posttypes') );
		if ( is_plugin_active( 'advanced-custom-fields-pro/acf.php' ) ) {
			add_submenu_page( 'mtools', 'MTools ACF', 'ACF', 'manage_options', 'mtools_acf',array($this,'mt_admin_acf') );
		}
		add_submenu_page( 'mtools', 'MTools Settings', 'Settings', 'manage_options', 'mtools_settings',array($this,'mt_admin_settings') );

		add_options_page('MTools Settings','MTools','manage_options','mtoolso_ptions',	array( $this, 'mt_admin_settings' ));
	}

	function mt_admin_settings() {
		echo '<div class="wrap">';
		echo '<h1>MTools Settings</h1>';
		if (isset($_GET['settings-updated']))  { ?>
			<div id="message" class="updated">
				<p>MTools settings saved.</p>
			</div>
		<?php }

		//add_settings_section('mtSettingsRestriction','Content Restriction',array($this,'mt_restriction_settings_callback'),'wp_mtools');

		//add_settings_field('mt_checkbox_show_restricted','Show Restricted Content in Lists',array($this,'mt_checkbox_show_restricted'),'wp_mtools','mtSettingsRestriction');

		add_settings_section('mtSettingsColumns','Post List Columns',array($this,'mt_column_settings_callback'),'wp_mtools');

		add_settings_field('mt_checkbox_show_fi','Show Featured Image',array($this,'mt_checkbox_show_fi'),'wp_mtools','mtSettingsColumns');
		add_settings_field('mt_checkbox_show_pid','Show Post ID',array($this,'mt_checkbox_show_pid'),'wp_mtools','mtSettingsColumns');
		add_settings_field('mt_checkbox_show_uid','Show User ID',array($this,'mt_checkbox_show_uid'),'wp_mtools','mtSettingsColumns');

		?>
		<form action="options.php" method="post">

			<?php
			settings_fields( 'wp_mtools' );
			do_settings_sections( 'wp_mtools' );
			submit_button();
			?>

		</form>
		<?php
		echo '</div>';
	}

	function mt_admin_welcome() {
		echo '<div class="wrap">';
		echo '<h1>MTools for WordPress</h1>';
		echo '<p>Use side menu to choose what you want to see</p>';
		echo '<p>Plugin &copy;2016-2018 DtD Productions. Licensed under GPL v2</p>';
		echo '<p>Portions of this plugin are based on other plugins as noted below:<br><br>';
		echo '<a href="http://wordpress.org/extend/plugins/clone-posts/">Clone Posts</a>, by Lukasz Kostrzewa, License GPL v2<br>';
		echo '<a href="https://wordpress.org/plugins/duplicate-post/">Duplicate Post</a>, by Enrico Battocchi, License GPL v2<br>';
		echo '<a href="https://wordpress.org/plugins/wp-crontrol/">WP Crontrol</a>, by John Blackbourn & Edward Dale, License GPL v2<br>';
		echo '<a href="https://wordpress.org/plugins/wordpress-php-info/">WordPress phpinfo()</a>, by Chris Flannagan, License GPL v2';
		echo '</p>';
		echo '</div>';
	}

	function mt_admin_info() {
		echo '<div class="wrap">';
		echo '<h1>System Info</h1>';
		global $wpdb;
		$this->info = array(
			'php'                   => php_uname(),
			'phpversion'            => phpversion(),
			'server'                => isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : getenv('SERVER_SOFTWARE'),
			'sapi_name'             => php_sapi_name(),
			'db_version'            => $wpdb->db_version(),
			'version'               => get_bloginfo('version'),
			'useragent'             => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : "",
		);
		?>
		<table class="widefat striped">
			<thead>
				<tr><th width="25%">Setting</th><th>Value</th></tr>
			</thead>
			<tfoot>
				<tr><td colspan="2">&#160;</td></tr>
			</tfoot>
			<tbody>
				<tr><td><strong>PHP Built On</strong></td><td><?php echo $this->info['php']; ?></td></tr>
				<tr><td><strong>PHP Version</strong></td><td><?php echo $this->info['phpversion']; ?></td></tr>
				<tr><td><strong>Web Server</strong></td><td><?php echo $this->info['server']; ?></td></tr>
				<tr><td><strong>Web Server to PHP Interface</strong></td><td><?php echo $this->info['sapi_name']; ?></td></tr>
				<tr><td><strong>Database Version</strong></td><td><?php echo $this->info['db_version']; ?></td></tr>
				<tr><td><strong>WordPress Version</strong></td><td><?php echo $this->info['version']; ?></td></tr>
				<tr><td><strong>User Agent</strong></td><td><?php echo htmlspecialchars($this->info['useragent'], ENT_COMPAT, 'UTF-8'); ?></td></tr>
			</tbody>
		</table>
		<?php


		echo '</div>';
	}

	function mt_admin_phpinfo() {
		if(function_exists('exec')) {
			// From WordPress phpinfo(): https://wordpress.org/plugins/wordpress-php-info/, by Chris Flannagan, License GPL v2
			echo '<div class="wrap">';
			ob_start();
			phpinfo( - 1 );
			$phpinfo_content = ob_get_contents();
			ob_end_clean();

			if ( ! empty( $phpinfo_content ) ) {
				$phpinfo_array = explode( '<table', $phpinfo_content );
			}

			if ( ! empty( $phpinfo_array ) ) {
				unset( $phpinfo_array[0] );
				foreach ( $phpinfo_array as $phpinfo_element ) {

					$phpinfo_element = str_replace( '<tr', '<tr valign="top"', $phpinfo_element );

					echo '<table class="widefat striped" ' . $phpinfo_element;
					echo '<div style="clear:both"></div>';
				}

			}
			echo '</div>';
		}
	}

	function mt_admin_acf() {
		echo '<div class="wrap">';
		echo '<h1>ACF Field Groups</h1>';
		if ( is_plugin_active( 'advanced-custom-fields-pro/acf.php' ) ) {

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

			echo '<hr>';
		} else {
			echo '<h2>ACF Is Not Installed</h2>';
		}
		echo '</div>';

	}

	function mt_admin_posttypes() {
		echo '<div class="wrap">';
		echo '<h1>Post Types</h1>';

		$args = array(
			'public'   => true,
			'_builtin' => true
		);

		$output = 'objects'; // names or objects, note names is the default
		$operator = 'or'; // 'and' or 'or'

		$post_types = get_post_types( $args, $output, $operator );

		foreach ( $post_types  as $post_type ) {
			echo '<h3>' . $post_type->name . '</h3>';
			echo '<table class="widefat striped">';
			foreach ($post_type as $pk => $pv) {
				if (is_object($pv) || is_array($pv)) {
					echo '<tr><td>'.$pk.'</td><td>';
					echo '<table class="widefat striped">';
					foreach ($pv as $ok => $ov) {
						echo '<tr><td>'.$ok.'</td><td>'.$ov.'</td></tr>';
					}
					echo '</table>';
					echo '</td></tr>';
				} else {
					echo '<tr><td>'.$pk.'</td><td>'.$pv.'</td></tr>';
				}
			}
			echo '</table>';
		}
		echo '</div>';

	}

	function mt_admin_cron() {
		echo '<div class="wrap">';

		// From WP Crontrol, https://wordpress.org/plugins/wp-crontrol/, by John Blackbourn & Edward Dale, License GPL v2
		echo '<h1>WP Cron Events</h1>';
		$events = $this->get_cron_events();
		?>
		<table class="widefat striped">
			<thead>
			<tr>
				<th><?php esc_html_e( 'Hook Name', 'wp-crontrol' ); ?></th>
				<th><?php esc_html_e( 'Arguments', 'wp-crontrol' ); ?></th>
				<th><?php esc_html_e( 'Next Run', 'wp-crontrol' ); ?></th>
				<th><?php esc_html_e( 'Recurrence', 'wp-crontrol' ); ?></th>
			</tr>
			</thead>
			<tbody>
			<?php
			if ( is_wp_error( $events ) ) {
				?>
				<tr><td colspan="7"><?php echo esc_html( $events->get_error_message() ); ?></td></tr>
				<?php
			} else {
				foreach ( $events as $id => $event ) {

					if ( $doing_edit && $doing_edit == $event->hook && $event->time == $_GET['next_run'] && $event->sig == $_GET['sig'] ) {
						$doing_edit = array(
							'hookname' => $event->hook,
							'next_run' => $event->time,
							'schedule' => ( $event->schedule ? $event->schedule : '_oneoff' ),
							'sig'      => $event->sig,
							'args'     => $event->args,
						);
					}

					if ( empty( $event->args ) ) {
						$args = __( 'None', 'wp-crontrol' );
					} else {
						if ( defined( 'JSON_UNESCAPED_SLASHES' ) ) {
							$args = wp_json_encode( $event->args, JSON_UNESCAPED_SLASHES );
						} else {
							$args = stripslashes( wp_json_encode( $event->args ) );
						}
					}

					echo '<tr id="cron-' . esc_attr( $id ) . '" class="">';

					if ( 'crontrol_cron_job' == $event->hook ) {
						echo '<td><em>' . esc_html__( 'PHP Cron', 'wp-crontrol' ) . '</em></td>';
						echo '<td><em>' . esc_html__( 'PHP Code', 'wp-crontrol' ) . '</em></td>';
					} else {
						echo '<td>' . esc_html( $event->hook ) . '</td>';
						echo '<td>' . esc_html( $args ) . '</td>';
					}

					echo '<td>';
					printf( '%s (%s)',
						esc_html( get_date_from_gmt( date( 'Y-m-d H:i:s', $event->time ), $time_format ) ),
						esc_html( $this->cron_time_since( time(), $event->time ) )
					);
					echo '</td>';

					if ( $event->schedule ) {
						echo '<td>';
						echo esc_html( $this->cron_interval( $event->interval ) );
						echo '</td>';
					} else {
						echo '<td>';
						esc_html_e( 'Non-repeating', 'wp-crontrol' );
						echo '</td>';
					}



					echo '</tr>';

				}
			}
			?>
			</tbody>
		</table>
		<?php
		echo '</div>';
	}

	public function get_cron_events() {
		// From WP Crontrol, https://wordpress.org/plugins/wp-crontrol/, by John Blackbourn & Edward Dale, License GPL v2

		$crons  = _get_cron_array();
		$events = array();

		if ( empty( $crons ) ) {
			return new WP_Error(
				'no_events',
				__( 'You currently have no scheduled cron events.', 'wp-crontrol' )
			);
		}

		foreach ( $crons as $time => $cron ) {
			foreach ( $cron as $hook => $dings ) {
				foreach ( $dings as $sig => $data ) {

					# This is a prime candidate for a Crontrol_Event class but I'm not bothering currently.
					$events[ "$hook-$sig-$time" ] = (object) array(
						'hook'     => $hook,
						'time'     => $time,
						'sig'      => $sig,
						'args'     => $data['args'],
						'schedule' => $data['schedule'],
						'interval' => isset( $data['interval'] ) ? $data['interval'] : null,
					);

				}
			}
		}

		return $events;

	}

	public function cron_time_since( $older_date, $newer_date ) {
		// From WP Crontrol, https://wordpress.org/plugins/wp-crontrol/, by John Blackbourn & Edward Dale, License GPL v2
		return $this->cron_interval( $newer_date - $older_date );
	}

	public function cron_interval( $since ) {
		// From WP Crontrol, https://wordpress.org/plugins/wp-crontrol/, by John Blackbourn & Edward Dale, License GPL v2
		// array of time period chunks
		$chunks = array(
			array( 60 * 60 * 24 * 365, _n_noop( '%s year', '%s years', 'wp-crontrol' ) ),
			array( 60 * 60 * 24 * 30, _n_noop( '%s month', '%s months', 'wp-crontrol' ) ),
			array( 60 * 60 * 24 * 7, _n_noop( '%s week', '%s weeks', 'wp-crontrol' ) ),
			array( 60 * 60 * 24, _n_noop( '%s day', '%s days', 'wp-crontrol' ) ),
			array( 60 * 60, _n_noop( '%s hour', '%s hours', 'wp-crontrol' ) ),
			array( 60, _n_noop( '%s minute', '%s minutes', 'wp-crontrol' ) ),
			array( 1, _n_noop( '%s second', '%s seconds', 'wp-crontrol' ) ),
		);

		if ( $since <= 0 ) {
			return __( 'now', 'wp-crontrol' );
		}

		// we only want to output two chunks of time here, eg:
		// x years, xx months
		// x days, xx hours
		// so there's only two bits of calculation below:

		// step one: the first chunk
		for ( $i = 0, $j = count( $chunks ); $i < $j; $i++ ) {
			$seconds = $chunks[ $i ][0];
			$name = $chunks[ $i ][1];

			// finding the biggest chunk (if the chunk fits, break)
			if ( ( $count = floor( $since / $seconds ) ) != 0 ) {
				break;
			}
		}

		// set output var
		$output = sprintf( translate_nooped_plural( $name, $count, 'wp-crontrol' ), $count );

		// step two: the second chunk
		if ( $i + 1 < $j ) {
			$seconds2 = $chunks[ $i + 1 ][0];
			$name2 = $chunks[ $i + 1 ][1];

			if ( ( $count2 = floor( ( $since - ( $seconds * $count ) ) / $seconds2 ) ) != 0 ) {
				// add to output var
				$output .= ' ' . sprintf( translate_nooped_plural( $name2, $count2, 'wp-crontrol' ), $count2 );
			}
		}

		return $output;
	}

	function mt_checkbox_show_fi(  ) {

		$options = get_option( 'wp_mtools' );
		?>
		<input type='checkbox' name='wp_mtools[show_column_fi]' <?php checked( $options['show_column_fi'], 1 ); ?> value='1'>
		<?php

	}

	function mt_checkbox_show_pid(  ) {

		$options = get_option( 'wp_mtools' );
		?>
		<input type='checkbox' name='wp_mtools[show_column_pid]' <?php checked( $options['show_column_pid'], 1 ); ?> value='1'>
		<?php

	}

	function mt_checkbox_show_uid(  ) {

		$options = get_option( 'wp_mtools' );
		?>
		<input type='checkbox' name='wp_mtools[show_column_uid]' <?php checked( $options['show_column_uid'], 1 ); ?> value='1'>
		<?php

	}

	function mt_checkbox_show_restricted(  ) {

		$options = get_option( 'wp_mtools' );
		?>
        <input type='checkbox' name='wp_mtools[show_restricted]' <?php checked( $options['show_restricted'], 1 ); ?> value='1'>
		<?php

	}

	function mt_restriction_settings_callback(  ) {

		echo __( 'Content restriction settings allow for the showing of posts in lists that require login to be accessed.', 'wordpress' );

	}

	function mt_column_settings_callback(  ) {

		echo __( 'Additional admin post list columns including ID, Featured Image and ACF fields', 'wordpress' );

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

	function mt_fi_head( $columns ) {
		$columns['featured_image']  = 'Featured Image';
		return $columns;
	}

	function mt_fi_content( $column_name, $post_id ) {
		if( $column_name == 'featured_image' ) { echo the_post_thumbnail( 'thumbnail' ); }
	}

	function mt_table_head( $columns ) {
		foreach ($this->fields as $f) {
			if (in_array($f['type'], array('text','url','radio','post_object','select','range','repeater')) ) {
				$columns[$f['name']] = $f['label'];
			}
		}
		return $columns;
	}

	function mt_table_content( $column_name, $post_id ) {
		foreach ($this->fields as $f) {
			if( $column_name == $f['name']) {
				$this->mt_table_content_field($f, $post_id);
			}
		}


	}

	function mt_table_content_field($f, $post_id) {
		if ($f['type'] == 'text' || $f['type'] == 'url' || $f['type'] == 'range') {
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
				echo implode('<br />',$answers);
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
			echo implode('<br />',$answers);
		}
		if ($f['type'] == 'repeater') {
			$value = get_field($f['name'], $post_id);
			//echo '<pre>';print_r($value);echo '</pre>';
			$sub_fields = get_field_object( $f['name'], $post_id, fasle, false )['sub_fields'];

			if ( $value ) {
                $num_subfields = count($sub_fields);
				if ($num_subfields > 1) {
					echo '<table class="wp-list-table widefat fixed striped">';
					echo '<thead><tr>';
					foreach ( $sub_fields as $sf ) {
						if ( in_array( $sf['type'],
							array( 'text', 'url', 'radio', 'post_object', 'select', 'range' ) ) ) {
							echo '<th>' . $sf['label'] . '</th>';
						}
					}
					echo '</tr></thead>';
					echo '<tbody>';
				}
				foreach ($value as $v) {
					if ($num_subfields > 1) echo '<tr>';
					foreach ( $sub_fields as $sf ) {
						if ( in_array( $sf['type'], array( 'text', 'url', 'radio', 'post_object', 'select', 'range' ) ) ) {
							if ($num_subfields > 1) echo '<td>';
							if ( $sf['type'] == 'text' || $sf['type'] == 'url' || $sf['type'] == 'range' ) {
								$value = $v[$sf['name']];
								echo $value;
							}
							if ( $sf['type'] == 'radio' || ( $sf['type'] == 'select' && ! $sf['multiple'] ) ) {
								$value = $v[$sf['name']];
								echo $sf['choices'][ $value ];
							}
							if ( $sf['type'] == 'select' && $sf['multiple'] ) {
								$values  = $v[$sf['name']];
								if ( count( $values ) && $values ) {
									foreach ( $values as $a ) {
										$answers[] = $f['choices'][ $a ];
									}
									echo implode( '<br />', $answers );
								}
							}
							if ( $sf['type'] == 'post_object' && ! $sf['multiple'] ) {
								$value = $v[$sf['name']];
								if ( ! is_object( $value ) ) {
									echo $this->posts[ $f['name'] ][ $value ];
								} else {
									echo $value->post_title;
								}
							}
							if ( $sf['type'] == 'post_object' && $sf['multiple'] ) {
								$values  = $v[$sf['name']];
								$answers = array();
								if ( count( $values ) && $values ) {
									foreach ( $values as $value ) {
										if ( ! is_object( $value ) ) {
											$answers[] = acf_get_post_title( $value );
										} else {
											$answers[] = $value->post_title;
										}
									}
								}
								echo implode( '<br />', $answers );
							}
							if ($num_subfields > 1) echo '</td>';
						}
					}
					if ($num_subfields > 1) echo '</tr>';
					else echo '<br />';
                }
				if ($num_subfields > 1) echo '</tbody></table>';
		    }
		}
    }

	function mt_table_sort( $columns ) {
		foreach ($this->fields as $f) {
			if ($f['type'] == 'text' || $f['type'] == 'url' || $f['type'] == 'radio' || $f['type'] == 'range' || ($f['type'] == 'select' && !$f['multiple']) ) {
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

	function mt_meta_box() {
		global $post;
		global $pagenow;

		//Nonce field
		wp_nonce_field( 'mt_save_meta_box_data', 'mt_meta_box_nonce' );

		// ID Field
		if ($pagenow=='post.php') {
			$id = $post->ID;
			echo '<strong>ID:</strong> ' . $id;
		}

        // Require Login Option
		if ($pagenow=='post.php') {
		    $value = get_post_meta( $post->ID, 'mt-require-login', true );
		    if (!$value) $value = 'No';
		}
		else $value = 'No';
		echo '<p><strong>Require Login</strong></p>';
		echo '<select id="mt-require-login" name="mt-require-login">';
		foreach ( array( 'Yes', 'No' ) as $val ) {
			echo '<option';
			if ( $val == $value ) echo ' selected="selected"';
			echo '>' . $val . '</option>';
		}
		echo '</select>';

		// Require Login Role
		global $wp_roles;
		if ($pagenow=='post.php') {
		    $value = get_post_meta( $post->ID, 'mt-require-login-role', true );
			if (!$value) $value = 'any';
		}
		else $value = 'any';
		echo '<p><strong>Require Login Role</strong></p>';
		echo '<select id="mt-require-login-role" name="mt-require-login-role">';
		echo '<option value="any"';
		if ( 'any' === $value ) echo ' selected="selected"';
		echo '>Any</option>';
		foreach ( $wp_roles->roles as $key => $role ) {
			echo '<option value="' . $key . '"';
			if ( $key === $value ) echo ' selected="selected"';
			echo '>' . $role['name'] . '</option>';
		}
		echo '</select>';


	}

	function mt_meta_boxes() {
		global $post;

		if ($post->post_type != 'acf-field-group') {
			add_meta_box( 'mtools_id', 'MTools', array( $this, 'mt_meta_box' ), null, 'side', 'high' );
		}
	}

	function mt_save_post($post_id, $post) {
		$nonce = $_POST['mt_meta_box_nonce'];
		if ( ! isset( $nonce ) || ! wp_verify_nonce( $nonce, 'mt_save_meta_box_data' ) ) return;
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;

		$require_login = sanitize_text_field( $_POST['mt-require-login'] );
		$role = sanitize_text_field( $_POST['mt-require-login-role'] );

		update_post_meta( $post_id, 'mt-require-login', $require_login );
		update_post_meta( $post_id, 'mt-require-login-role', $role );
    }

	function mt_template_redirect() {
		global $post;

		if (is_single()) {
			$require_login = get_post_meta( $post->ID, 'mt-require-login', true );
			$role          = get_post_meta( $post->ID, 'mt-require-login-role', true );
			$user          = wp_get_current_user();

			if ( $require_login === 'Yes' ) {
				if ( is_user_logged_in() ) {
					if ( $role != 'any' && ! $user->has_cap( 'administrator' ) ) {
						if ( ! $user->has_cap( $role ) ) {
							global $wp_query;
							$wp_query->set_404();
							status_header( 404 );
							get_template_part( 404 );
							exit();
						}
					}
				} else {
					auth_redirect();
				}
			}
		}
	}

	function mt_show_allowed_posts ( $query ) {
		$options = get_option( 'wp_mtools' );
		if (!is_user_logged_in() && !$options['show_restricted']) {
			$meta_query = array(
			        'relation'=>'OR',
                    array(
                        'key'=>'mt-require-login',
                        'value'=>'yes',
                        'compare'=>'!=',
                    ),
                    array(
                        'key'=>'mt-require-login',
                        'compare'=>'NOT EXISTS',
                    ),
			);
			$query->set('meta_query',$meta_query);
		}
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


	function mt_acf_gforms_field( $version ) {
		include_once('acf_gforms_field.php');
	}
}