<?php

namespace uncanny_custom_toolkit;

use uncanny_learndash_toolkit as toolkit;
use uncanny_pro_toolkit as toolkitPro;

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class LegacyfmImportGroups
 * @package uncanny_pro_toolkit
 */
class LegacyfmImportGroups extends toolkit\Config implements toolkit\RequiredFunctions {

    static $group_identifier_key = '_uo_group_identifier';

    /**
     * Stores HTML for import results to display after the form
     *
     * @var string
     */
    private static $import_results = '';

    /**
     * Constructor: setup hooks
     */
    public function __construct() {
        add_action( 'plugins_loaded', [ __CLASS__, 'run_frontend_hooks' ] );
    }

    /**
     * Register admin actions when dependencies are present
     */
    public static function run_frontend_hooks() {
        if ( true === self::dependants_exist() ) {
            add_action( 'admin_menu', [ __CLASS__, 'menu_import_groups_page' ], 5 );
            add_action( 'admin_init', [ __CLASS__, 'process_csv_upload' ] );
            add_action( 'add_meta_boxes', [ __CLASS__, 'uo_add_meta_box' ], 100 );
            add_action( 'save_post', [ __CLASS__, 'save_post' ], 10, 3 );
        }
    }

    /**
     * Check for LearnDash dependency
     *
     * @return bool|string
     */
    public static function dependants_exist() {
        global $learndash_post_types;
        if ( ! isset( $learndash_post_types ) ) {
            return 'Plugin: LearnDash';
        }
        return true;
    }

    /**
     * Details for Uncanny Toolkit listing
     */
    public static function get_details() {
        $class_title = esc_html__( 'Legacy Import Groups', 'uncanny-pro-toolkit' );
        $kb_link      = 'http://www.uncannyowl.com/knowledge-base/import-groups/';
        $class_description = esc_html__( 'Imports groups from a CSV file.', 'uncanny-pro-toolkit' );
        $class_icon   = '<i class="uo_icon_pro_fa uo_icon_fa fa fa-table "></i><span class="uo_pro_text">PRO</span>';
        return array(
            'title'            => $class_title,
            'type'             => 'pro',
            'category'         => 'learndash',
            'kb_link'          => $kb_link,
            'description'      => $class_description,
            'dependants_exist' => self::dependants_exist(),
            'icon'             => $class_icon,
            'settings'         => false,
        );
    }

    /**
     * Settings HTML (none for this tool)
     */
    public static function get_class_settings( $class_title ) {
        return self::settings_output( array(
            'class'   => __CLASS__,
            'title'   => $class_title,
            'options' => array(),
        ) );
    }

    /**
     * Add submenu page under LearnDash LMS
     */
    public static function menu_import_groups_page() {
        add_submenu_page(
            'learndash-lms',
            esc_html__( 'Import Groups', 'uncanny-pro-toolkit' ),
            esc_html__( 'Import Groups', 'uncanny-pro-toolkit' ),
            'manage_options',
            'import-groups',
            [ __CLASS__, 'import_ld_groups_page' ]
        );
    }

    /**
     * Output the admin page HTML
     */
    public static function import_ld_groups_page() {
        wp_enqueue_style(
            'uncanny-pro-toolkit',
            plugins_url( basename( dirname( UO_CUSTOM_TOOLKIT_FILE ) ) ) . '/src/assets/legacy/frontend/css/admin-style.css',
            false,
            '1.0.0'
        );
        ?>
        <div class="uo-csup-admin wrap">
            <div class="uo-plugins-header">
                <div class="uo-plugins-header__title"><?php esc_html_e( 'Import LearnDash Groups', 'uncanny-pro-toolkit' ); ?></div>
                <div class="uo-plugins-header__author">
                    <span>by</span>
                    <a href="https://uncannyowl.com" target="_blank" class="uo-plugins-header__logo">
                        <img src="<?php echo esc_url( plugins_url( basename( dirname( UO_CUSTOM_TOOLKIT_FILE ) ) ) . '/src/assets/legacy/backend/img/uncanny-owl-logo.svg' ); ?>" alt="Uncanny Owl">
                    </a>
                </div>
            </div>

            <?php if ( isset( $_REQUEST['saved'] ) && $_REQUEST['saved'] ) : ?>
                <div class="updated notice"><?php _e( 'Settings Saved!', 'uncanny-pro-toolkit' ); ?></div>
            <?php endif; ?>

            <?php if ( defined( 'UOC_ERROR_MESSAGE' ) ) : ?>
                <div class="notice notice-error"><?php echo UOC_ERROR_MESSAGE; ?></div>
            <?php endif; ?>

            <form method="post" action="" id="uo-csup-form" enctype="multipart/form-data">
                <input type="hidden" name="_wp_http_referer" value="<?php echo esc_url( admin_url( 'admin.php?page=import-groups&saved=true' ) ); ?>" />
                <input type="hidden" name="_wpnonce" value="<?php echo esc_attr( wp_create_nonce( 'uncanny-import-groups' ) ); ?>" />

                <div class="uo-admin-section">
                    <div class="uo-admin-header">
                        <div class="uo-admin-title"><?php esc_html_e( 'Import Report', 'uncanny-pro-toolkit' ); ?></div>
                    </div>
                    <div class="uo-admin-block">
                        <div class="uo-admin-form">
                            <div class="uo-admin-field">
                                <div class="uo-admin-label"><?php esc_html_e( 'Upload CSV File', 'uncanny-pro-toolkit' ); ?></div>
                                <input type="file" name="uo_import_csv_groups" id="uo_import_csv_groups" accept=".csv" />
                            </div>
                            <div class="uo-admin-field">
                                <input type="submit" name="submit" id="submit" class="uo-admin-form-submit" value="<?php esc_attr_e( 'Submit', 'uncanny-pro-toolkit' ); ?>" />
                            </div>
                        </div>
                    </div>
                </div>

                <?php
                // Display import results immediately below the upload controls
                if ( ! empty( self::$import_results ) ) {
                    echo self::$import_results;
                }
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Process the uploaded CSV, creating/updating groups and building result tables
     */
    public static function process_csv_upload() {
        if ( empty( $_POST ) || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'uncanny-import-groups' ) ) {
            return;
        }

        $file_tmp = $_FILES['uo_import_csv_groups']['tmp_name'] ?? '';
        $ext      = pathinfo( $_FILES['uo_import_csv_groups']['name'] ?? '', PATHINFO_EXTENSION );

        $group_data               = [];
        $new_groups               = [];
        $updated_groups           = [];
        $failed_groups_row        = [];
        $failed_groups_row_reason = [];
        $success_groups           = 0;
        $failed_groups            = 0;

        // Basic file checks
        if ( empty( $file_tmp ) ) {
            define( 'UOC_ERROR_MESSAGE', __( 'ERROR', 'uncanny-pro-toolkit' ) . ': ' . __( 'No files chosen to upload!', 'uncanny-pro-toolkit' ) );
            return;
        }
        if ( strtolower( $ext ) !== 'csv' ) {
            define( 'UOC_ERROR_MESSAGE', __( 'ERROR', 'uncanny-pro-toolkit' ) . ': ' . __( 'Only CSV files are allowed!', 'uncanny-pro-toolkit' ) );
            return;
        }

        // Read CSV
        $file = fopen( $file_tmp, 'r' );
        if ( ! $file ) {
            define( 'UOC_ERROR_MESSAGE', __( 'ERROR', 'uncanny-pro-toolkit' ) . ': ' . __( 'Could not open file.', 'uncanny-pro-toolkit' ) );
            return;
        }

        $row_count = 0;
        while ( ( $data = fgetcsv( $file, 1000, ',' ) ) !== false ) {
            // Remove BOM / utf8-encode
            $data = array_map( function( $s ) {
                return substr( $s, 0, 3 ) === chr(0xEF) . chr(0xBB) . chr(0xBF) ? substr( $s, 3 ) : $s;
            }, $data );
            $data = array_map( 'utf8_encode', $data );

            if ( $row_count === 0 ) {
                if ( count( $data ) !== 3 || trim( $data[0] ) !== 'group_name' || trim( $data[1] ) !== 'group_identifier' || trim( $data[2] ) !== 'group_parent' ) {
                    define( 'UOC_ERROR_MESSAGE', __( 'ERROR', 'uncanny-pro-toolkit' ) . ': ' . __( 'Invalid CSV header format!', 'uncanny-pro-toolkit' ) );
                    fclose( $file );
                    return;
                }
            } else {
                if ( count( $data ) !== 3 || empty( $data[0] ) || empty( $data[1] ) ) {
                    define( 'UOC_ERROR_MESSAGE', __( 'ERROR', 'uncanny-pro-toolkit' ) . ': ' . __( 'Missing required values in CSV!', 'uncanny-pro-toolkit' ) );
                    fclose( $file );
                    return;
                }
                $group_data[] = [
                    'group_name'       => sanitize_text_field( $data[0] ),
                    'group_identifier' => sanitize_key( $data[1] ),
                    'group_parent'     => sanitize_key( $data[2] ),
                ];
            }
            $row_count++;
        }
        fclose( $file );

        global $wpdb;
        $postmeta = $wpdb->postmeta;
        $posts    = $wpdb->posts;

        foreach ( $group_data as $index => $row ) {
            $csv_row = $index + 2;
            if ( empty( $row['group_name'] ) ) {
                $failed_groups++; $failed_groups_row[] = $csv_row; $failed_groups_row_reason[] = 'Missing group name'; continue;
            }
            if ( empty( $row['group_identifier'] ) ) {
                $failed_groups++; $failed_groups_row[] = $csv_row; $failed_groups_row_reason[] = 'Missing group identifier'; continue;
            }
            $parent_id = 0;
            if ( ! empty( $row['group_parent'] ) ) {
                $parent_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT post_id FROM {$postmeta} pm JOIN {$posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value = %s AND p.post_type = 'groups' AND p.post_status = 'publish' LIMIT 1",
                    self::$group_identifier_key,
                    $row['group_parent']
                ) );
                if ( ! $parent_id ) {
                    $failed_groups++; $failed_groups_row[] = $csv_row; $failed_groups_row_reason[] = 'Parent group not found'; continue;
                }
            }
            $existing = new \WP_Query([
                'post_type'=>'groups','posts_per_page'=>1,'meta_query'=>[[ 'key'=>self::$group_identifier_key,'value'=>$row['group_identifier'] ]]
            ]);
            if ( $existing->have_posts() ) {
                $group_id = $existing->posts[0]->ID;
                wp_update_post([ 'ID' => $group_id, 'post_parent' => $parent_id ]);
                    // Allow course management
                    update_post_meta( $group_id, 'is_course_management_allowed', '1' );
                $updated_groups[]=[ 'row'=>$csv_row,'group_name'=>$row['group_name'],'parent_identifier'=>$row['group_parent'] ];
            } else {
                $new_id=wp_insert_post([ 'post_title'=>$row['group_name'],'post_type'=>'groups','post_status'=>'publish','post_parent'=>$parent_id ]);
                if ( is_wp_error($new_id) || !$new_id ) {
                    $failed_groups++; $failed_groups_row[] = $csv_row; $failed_groups_row_reason[] = 'Failed to create group';
                } else {
                    update_post_meta( $new_id, self::$group_identifier_key, $row['group_identifier'] );
                    // Allow course management
                    update_post_meta( $new_id, 'is_course_management_allowed', '1' );
                    $success_groups++;
                    $new_groups[]=[ 'row'=>$csv_row,'group_name'=>$row['group_name'],'post_id'=>$new_id,'parent_identifier'=>$row['group_parent'] ];
                }
            }
        }
        $msg='<p>'.__('Groups Added:','uncanny-pro-toolkit').' '.$success_groups.'</p>';
        if($failed_groups){
            $msg.='<p>'.__('Rows with issues:','uncanny-pro-toolkit').' '.implode(', ',$failed_groups_row).'</p>';
            $msg.='<p>'.__('Issues reason:','uncanny-pro-toolkit').' '.implode(', ',$failed_groups_row_reason).'</p>';
            define('UOC_ERROR_MESSAGE',__('ERROR','uncanny-pro-toolkit').': '.$msg);
        } else {
            define('UOC_ERROR_MESSAGE',__('DONE','uncanny-pro-toolkit').': '.$msg);
        }
        ob_start();
        echo '<div class="uo-import-results">';
        // Summary notice will be shown above the form via admin notice hook
        echo '<h2>' . __('New Groups','uncanny-pro-toolkit') . '</h2>';
        if($new_groups){echo '<table class="widefat"><thead><tr><th>Row</th><th>Name</th><th>Post ID</th><th>Parent Group</th></tr></thead><tbody>';foreach($new_groups as $g){printf('<tr><td>%d</td><td>%s</td><td>%d</td><td>%s</td></tr>',$g['row'],esc_html($g['group_name']),esc_html($g['post_id']),esc_html($g['parent_identifier']));}echo '</tbody></table>';}else{echo '<p>'.__('No new groups created.','uncanny-pro-toolkit').'</p>';}        echo '<h2>'.__('Existing Groups','uncanny-pro-toolkit').'</h2>';
        if($updated_groups){echo '<table class="widefat"><thead><tr><th>Row</th><th>Name</th><th>Parent Group</th></tr></thead><tbody>';foreach($updated_groups as $g){printf('<tr><td>%d</td><td>%s</td><td>%s</td></tr>',$g['row'],esc_html($g['group_name']),esc_html($g['parent_identifier']));}echo '</tbody></table>';}else{echo '<p>'.__('No existing groups updated.','uncanny-pro-toolkit').'</p>';}        echo '<h2>'.__('Upload Errors','uncanny-pro-toolkit').'</h2>';
        if($failed_groups_row){echo '<table class="widefat"><thead><tr><th>Row</th><th>Reason</th></tr></thead><tbody>';foreach($failed_groups_row as $i=>$row_num){printf('<tr><td>%d</td><td>%s</td></tr>',$row_num,esc_html($failed_groups_row_reason[$i]));}echo '</tbody></table>';}else{echo '<p>'.__('No errors.','uncanny-pro-toolkit').'</p>';}        echo '</div>';
        self::$import_results=ob_get_clean();
    }

    /**
     * Remove BOM from UTF-8 string
     */
    public static function removeBomUtf8( $s ) {
        return substr($s,0,3)===chr(0xEF).chr(0xBB).chr(0xBF)?substr($s,3):$s;
    }

    /**
     * Add metabox to group edit screen
     */
    public static function uo_add_meta_box() {
        add_meta_box(
            'uncanny_import_group_identifier',
            sprintf(esc_html_x('%s Identifier','placeholder: Group','uncanny-pro-toolkit'),learndash_get_custom_label('group')),
            [__CLASS__,'uncanny_group_identifier_metabox_content'],
            learndash_get_post_type_slug('group'),
            'side'
        );
    }

    /**
     * Metabox content HTML
     */
    public static function uncanny_group_identifier_metabox_content( $post ) {
        if($post->post_type==='groups'){
            $identifier=get_post_meta($post->ID,self::$group_identifier_key,true);
            wp_nonce_field('uo-group-identifier-metabox-nonce','uo-group-identifier-metabox-nonce');
            ?>
            <p><label for="<?php echo self::$group_identifier_key;?>"><?php esc_html_e('Group Identifier','uncanny-pro-toolkit');?></label></p>
            <input name="<?php echo self::$group_identifier_key;?>" type="text" id="<?php echo self::$group_identifier_key;?>" value="<?php echo esc_attr($identifier);?>" />
            <?php
        }
    }

    /**
     * Save metabox data
     */
    public static function save_post($post_id,$post,$update){
        if(isset($_POST['uo-group-identifier-metabox-nonce'])&&wp_verify_nonce($_POST['uo-group-identifier-metabox-nonce'],'uo-group-identifier-metabox-nonce')){
            if(isset($_POST[self::$group_identifier_key])){
                update_post_meta($post_id,self::$group_identifier_key,sanitize_key($_POST[self::$group_identifier_key]));
            }
        }
    }
}
