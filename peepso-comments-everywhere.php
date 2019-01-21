<?php
/**
 * Plugin Name: PeepSo Comments Everywhere
  * Description: Put PeepSo Comments on all post types
 * Author: Scott Severt
 * Version: 0.0.1
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Domain Path: /language
 *
 * We are Open Source. You can redistribute and/or modify this software under the terms of the GNU General Public License (version 2 or later)
 * as published by the Free Software Foundation. See the GNU General Public License or the LICENSE file for more details.
 * This software is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY.
 */

class PeepSoCommentsEverywhere
{
    const COMMENTS_SHORTCODE = 'peepso_comments_everywhere';
	const COMMENTS_MODULE_ID = '123456';
	private static $_instance = NULL;

	const PLUGIN_NAME	 = 'PeepSo Comments Everywhere';
	const PLUGIN_VERSION = '0.0.1';
	const PLUGIN_RELEASE = ''; //ALPHA1, BETA1, RC1, '' for STABLE

    public static function get_instance()
    {
        if (self::$_instance === NULL)
            self::$_instance = new self();
        return (self::$_instance);
    }

    function create_peepso_activity( $ID, $post  ) {
        // skip regular posts (peepso core can handle them)
        if('post' == $post->post_type) 			                                            {	return( FALSE );	}
		//so we don't loop
		if('peepso-post' == $post->post_type) 			                                            {	return( FALSE );	}
		//if('peepso-post' == $post->post_type)												(	return(	FALSE );	}

        // is the post published?
        if(!in_array($post->post_status,  array('publish')))         			            {	return( FALSE );	}
	echo "2<br>";

        // is activity posting enabled?
        if(0 == PeepSo::get_option('blogposts_activity_enable', 0 )) 			{	return( FALSE );	}
	echo "3<br>";

        // is this post type enabled?
        // if(!PeepSo::get_option('blogposts_activity_type_'.$post->post_type, 0)) {	return( FALSE );	}

        // check if it's not marked as already posted to activity and has valid act_id
        $act_id = get_post_meta($ID, self::COMMENTS_SHORTCODE, TRUE);
	echo "act_id = " . $act_id . "<p>";
	//die;
        //if(strlen($act_id) && is_numeric($act_id) && 0 < $act_id) 				            {	return( NULL );	}

        // author is not always the current user - ie when admin publishes a post written by someone else
        $author_id = $post->post_author;

        // skip blacklisted author IDs
        $blacklist = array();
        if(in_array($author_id, $blacklist))                                                {   return( FALSE );    }

        // build JSON to be used as post content for later display
        $content = array(
            'post_id' => $ID,
            'post_type' => $post->post_type,
            'shortcode' => self::COMMENTS_SHORTCODE,
            'permalink' => get_permalink($ID),
        );

        $extra = array(
            'module_id' => self::COMMENTS_MODULE_ID,
            'act_access'=> PeepSo::get_option('blogposts_activity_privacy',PeepSoUser::get_instance($author_id)->get_profile_accessibility()),
            'post_date'		=> $post->post_date,
            'post_date_gmt' => $post->post_date_gmt,
        );

        $content=json_encode($content);

        // create an activity item
        $act = PeepSoActivity::get_instance();
        $act_id = $act->add_post($author_id, $author_id, $content, $extra);

        update_post_meta($act_id, '_peepso_display_link_preview', 0);
        delete_post_meta($act_id, 'peepso_media');

        // mark this post as already posted to activity
        add_post_meta($ID, self::COMMENTS_SHORTCODE, $act_id, TRUE);

        return TRUE;
    }

	public function AddCommentsToPost()
	{
		if (true == is_front_page()) { return;}
        global $post;
        global $wpdb;
		if ('post' == get_post_type($post)) { return;}

        $peepso_actions ='';
        $peepso_comments = '';
        $peepso_wrapper = '';

		// completely disable and hide native WP comments
		remove_post_type_support('post', 'comments');
		remove_post_type_support('page', 'comments'); //scottsevert

		add_filter('comments_array', function () { return array(); });
		add_filter('comments_open', function () { return FALSE; });
		add_filter('pings_open', function () { return FALSE; });

		// $act_external_id - ID of post representing the stream activity
		$act_external_id = get_post_meta($post->ID, self::COMMENTS_SHORTCODE, TRUE);
		if($act_external_id==0 || $act_external_id==1 ||  !is_numeric($act_external_id)) {

			// extract act_id from wp_posts by searching for the serialized data
			$search = '{"post_id":'.$post->ID.',';

			$q = "SELECT ID FROM {$wpdb->prefix}posts WHERE `post_content` LIKE '%$search%'";
			$r = $wpdb->get_row($q);

			$act_external_id = (int) $r->ID;

			// update postmeta with new value so we don't have to search again
			update_post_meta($post->ID, self::COMMENTS_SHORTCODE, $act_external_id);
		}

		// don't modify content in the embed
		if(is_embed()) { return; }

		// stash the original post object
		$post_old = $post;

		// post object representing the stream item
		$post = get_post($act_external_id);

		// if post can't be found
		// probably it was deleted and there is orphan data in peepso_activities and postmeta
		if(!$post) {
			ob_start();
			echo ' <br/><br/> '.__('Can\'t load comments and likes. Try refreshing the page or contact the Administrators.','peepso-core');

			$wpdb->delete($wpdb->prefix.'postmeta', array('meta_value'=>$act_external_id, 'meta_key'=>self::COMMENTS_SHORTCODE));
			$wpdb->delete($wpdb->prefix.PeepSoActivity::TABLE_NAME, array('act_external_id'=>$act_external_id, 'act_module_id'=>self::COMMENTS_MODULE_ID));

			return ob_get_clean();
		}

		$PeepSoActivity = new PeepSoActivity();

		// act_id - id of the item in peepso_activities representing the stream item
		$r = $wpdb->get_row("SELECT act_id FROM ".$wpdb->prefix.$PeepSoActivity::TABLE_NAME." WHERE act_module_id=".$this::COMMENTS_MODULE_ID." and act_external_id=$act_external_id");
		$act_id = $r->act_id;

		$post->act_id = $act_id;
		$post->act_module_id = self::COMMENTS_MODULE_ID;
		$post->act_external_id = $act_external_id;

		// PEEPSO WRAPPER
		ob_start();

		$data = array(
			'header'            => PeepSo::get_option('blogposts_comments_header_call_to_action'),
			'header_comments'   => PeepSo::get_option('blogposts_comments_header_comments'),
		);

		$data['header_actions'] = PeepSo::get_option('blogposts_comments_header_reactions');

		if(is_user_logged_in()) {
			PeepSoTemplate::exec_template('blogposts','peepso_wrapper', $data);
		} else {
			PeepSoTemplate::exec_template('blogposts','peepso_wrapper_guest', $data);
		}
		$peepso_wrapper = '<div id="peepso-wrap">' . ob_get_clean() . '</div>';

		// POST ACTIONS
		ob_start();
		add_action('peepso_activity_post_actions', function( $args ){ return array('post'=>$args['post'],'acts'=>array('like'=>$args['acts']['like']));}, 20);
		?>
		<div class="ps-stream-actions stream-actions" data-type="stream-action"><?php $PeepSoActivity->post_actions(); ?></div>

		<?php
		if ($likes = $PeepSoActivity->has_likes($act_id)) { ?>
			<div id="act-like-<?php echo $act_id; ?>"
				 class="ps-stream-status cstream-likes ps-js-act-like--<?php echo $act_id; ?>"
				 data-count="<?php echo $likes ?>">
				<?php $PeepSoActivity->show_like_count($likes); ?>
			</div>
		<?php } else { ?>
			<div id="act-like-<?php echo $act_id; ?>"
				 class="ps-stream-status cstream-likes ps-js-act-like--<?php echo $act_id; ?>" data-count="0"
				 style="display:none"></div>
		<?php }

		do_action('peepso_post_before_comments');
		$peepso_actions = ob_get_clean();

		// POST COMMENTS
		ob_start();
		$PeepSoActivity->show_recent_comments();
		$comments = ob_get_clean();

		ob_start();

		$show_commentsbox = apply_filters('peepso_commentsbox_display', apply_filters('peepso_permissions_comment_create', is_user_logged_in()), $post->ID);

		// show "no comments yet" only if the user can't make a new one
		if(!strlen($comments) && !$show_commentsbox) {
			?>
			<div class="ps-no-comments-container--<?php echo $act_id; ?>">
				<?php echo __('No comments yet', 'peepso-core');?>
			</div>
			<?php
		}

		?>
		<div class="ps-comments--blogpost ps-comment-container comment-container ps-js-comment-container ps-js-comment-container--<?php echo $act_id; ?>" data-act-id="<?php echo $act_id; ?>">
			<?php echo $comments;  ?>
		</div>
		<?php
		if (is_user_logged_in() && $show_commentsbox ) {
			$PeepSoUser = PeepSoUser::get_instance();
			?>

			<div id="act-new-comment-<?php echo $act_id; ?>" class="ps-comments--blogpost ps-comment-reply cstream-form stream-form wallform ps-js-newcomment-<?php echo $act_id; ?> ps-js-comment-new" data-type="stream-newcomment" data-formblock="true">
				<a class="ps-avatar cstream-avatar cstream-author" href="<?php echo $PeepSoUser->get_profileurl(); ?>">
					<img data-author="<?php echo $post->post_author; ?>" src="<?php echo $PeepSoUser->get_avatar(); ?>" alt="" />
				</a>
				<div class="ps-textarea-wrapper cstream-form-input">
			<textarea
					data-act-id="<?php echo $act_id;?>"
					class="ps-textarea cstream-form-text"
					name="comment"
					oninput="return activity.on_commentbox_change(this);"
					placeholder="<?php _e('Write a comment...', 'peepso-core');?>"></textarea>
					<?php
					// call function to add button addons for comments
					$PeepSoActivity->show_commentsbox_addons();
					?>
				</div>
				<div class="ps-comment-send cstream-form-submit" style="display:none;">
					<div class="ps-comment-loading" style="display:none;">
						<img src="<?php echo PeepSo::get_asset('images/ajax-loader.gif'); ?>" alt="" />
						<div> </div>
					</div>
					<div class="ps-comment-actions" style="display:none;">
						<button onclick="return activity.comment_cancel(<?php echo $act_id; ?>);" class="ps-btn ps-button-cancel"><?php _e('Clear', 'peepso-core'); ?></button>
						<button onclick="return activity.comment_save(<?php echo $act_id; ?>, this);" class="ps-btn ps-btn-primary ps-button-action" disabled><?php _e('Post', 'peepso-core'); ?></button>
					</div>
				</div>
			</div>

		<?php }
		if (strlen($reason = apply_filters('peepso_permissions_comment_create_denied_reason', ''))) {
			echo '<div class="ps-alert ps-alert-warning">' . $reason . '</div>';
		}
		$peepso_comments = ob_get_clean();

		// restore original post object
		$post = $post_old;


		$from = array(
			'{peepso_comments}',
			'{peepso_actions}',
		);

		$to = array(
			$peepso_comments,
			$peepso_actions,
		);

		echo str_ireplace($from, $to, $peepso_wrapper);
	}


	function init_cb(){
		if (current_user_can('edit_others_posts')) {
			add_action('comment_form_comments_closed',array($this, 'AddCommentsToPost'));
		}
		// Post publish action
		add_action( 'wp_insert_post', array(&$this, 'create_peepso_activity'), 10, 2 );

	}
	private function __construct() {
		add_action('init',array($this,'init_cb'));
	}
}

PeepSoCommentsEverywhere::get_instance();

// EOF
