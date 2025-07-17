<?php

namespace uncanny_custom_toolkit;

use uncanny_learndash_toolkit as toolkit;
use uncanny_pro_toolkit as toolkitPro;

if ( ! defined( 'WPINC' ) ) {
    die;
}

/**
 * Class LegacyfmGroupsMissingIdentifier
 * @package uncanny_pro_toolkit
 */
class LegacyfmGroupsMissingIdentifier extends toolkit\Config implements toolkit\RequiredFunctions {

    // Meta key for legacy identifier
    static $group_identifier_key = '_uo_group_identifier';

    // Holds generated HTML report
    private static $report_html = '';

    // Holds error message for invalid input
    private static $error_message = '';

    // Constructor: hook into WordPress
    public function __construct() {
        add_action( 'plugins_loaded', [ __CLASS__, 'run_frontend_hooks' ] );
    }

    // Register admin hooks
    public static function run_frontend_hooks() {
        if ( true === self::dependants_exist() ) {
            add_action( 'admin_menu', [ __CLASS__, 'menu_missing_id_page' ], 6 );
            add_action( 'admin_init', [ __CLASS__, 'process_report' ] );
            add_action( 'add_meta_boxes', [ __CLASS__, 'uo_add_meta_box' ], 100 );
            add_action( 'save_post', [ __CLASS__, 'save_post' ], 10, 3 );
        }
    }

    // Check that LearnDash exists
    public static function dependants_exist() {
        global $learndash_post_types;
        return isset( $learndash_post_types ) ? true : 'Plugin: LearnDash';
    }

    // Toolkit details
    public static function get_details() {
        return array(
            'title'            => esc_html__( 'Groups With No Identifier Report', 'uncanny-pro-toolkit' ),
            'type'             => 'pro',
            'category'         => 'learndash',
            'kb_link'          => '',
            'description'      => esc_html__( 'List groups under a certain parent that are missing an identifier', 'uncanny-pro-toolkit' ),
            'dependants_exist' => self::dependants_exist(),
            'icon'             => '<i class="uo_icon_pro_fa uo_icon_fa fa fa-table"></i><span class="uo_pro_text">PRO</span>',
            'settings'         => false,
        );
    }

    // No settings for this tool
    public static function get_class_settings( $class_title ) {
        return self::settings_output( array(
            'class'   => __CLASS__,
            'title'   => $class_title,
            'options' => array(),
        ) );
    }

    // Add submenu under LearnDash LMS
    public static function menu_missing_id_page() {
        add_submenu_page(
            'learndash-lms',
            esc_html__( 'Groups Missing ID', 'uncanny-pro-toolkit' ),
            esc_html__( 'Groups Missing ID', 'uncanny-pro-toolkit' ),
            'manage_options',
            'groups-missing-id',
            [ __CLASS__, 'missing_id_page' ]
        );
    }

    // Render the report page
    public static function missing_id_page() {
        // Enqueue admin styles for consistent header styling
        wp_enqueue_style(
            'uncanny-pro-toolkit',
            plugins_url( basename( dirname( UO_CUSTOM_TOOLKIT_FILE ) ) ) . '/src/assets/legacy/frontend/css/admin-style.css',
            false,
            '1.0.0'
        );
        ?>
        <div class="uo-csup-admin wrap">
            <div class="uo-plugins-header">
                <div class="uo-plugins-header__title"><?php esc_html_e( 'Groups With No Identifier', 'uncanny-pro-toolkit' ); ?></div>
                <div class="uo-plugins-header__author">
                    <span>by</span>
                    <a href="https://uncannyowl.com" target="_blank" class="uo-plugins-header__logo">
                        <img src="<?php echo esc_url( plugins_url( basename( dirname( UO_CUSTOM_TOOLKIT_FILE ) ) ) . '/src/assets/legacy/backend/img/uncanny-owl-logo.svg' ); ?>" alt="Uncanny Owl">
                    </a>
                </div>
            </div>
            <h2><?php esc_html_e( 'Generate Group Report', 'uncanny-pro-toolkit' ); ?></h2>

            <form method="post" action="" autocomplete="off">
                <?php wp_nonce_field( 'uncanny-missing-id', 'uo_missing_id_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th><label for="parent_group_id"><?php esc_html_e( 'Group Post ID', 'uncanny-pro-toolkit' ); ?></label></th>
                        <td><input name="parent_group_id" type="text" id="parent_group_id" value="<?php echo esc_attr( $_POST['parent_group_id'] ?? '' ); ?>" class="regular-text" /></td>
                    </tr>
                </table>
                <?php submit_button( __( 'Submit', 'uncanny-pro-toolkit' ), 'primary', 'generate_report' ); ?>
            </form>

            <?php if ( self::$error_message ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( self::$error_message ); ?></p></div>
            <?php endif; ?>

            <?php echo self::$report_html; ?>
        </div>
        <?php
    }

    // Handle form submission and build report
    public static function process_report() {
        if ( empty( $_POST['generate_report'] ) ) {
            return;
        }
        if ( ! isset( $_POST['uo_missing_id_nonce'] ) || ! wp_verify_nonce( $_POST['uo_missing_id_nonce'], 'uncanny-missing-id' ) ) {
            return;
        }
        $parent_id = intval( $_POST['parent_group_id'] );
        if ( $parent_id <= 0 || get_post( $parent_id ) === null || get_post_type( $parent_id ) !== 'groups' ) {
            self::$error_message = __( 'Enter a valid group post ID.', 'uncanny-pro-toolkit' );
            return;
        }

        // Collect all child groups
        $all_ids = self::get_all_child_groups( $parent_id );
        $rows = array();
        foreach ( $all_ids as $gid ) {
            $post       = get_post( $gid );
            $identifier = get_post_meta( $gid, self::$group_identifier_key, true );
            $rows[] = array(
                'name'       => $post->post_title,
                'id'         => $gid,
                'parent'     => $post->post_parent,
                'identifier' => $identifier,
            );
        }

        // Check missing identifier
        $missing = array_filter( $rows, fn($r) => empty( $r['identifier'] ) );
        if ( empty( $missing ) ) {
            self::$report_html = '<p>' . esc_html__( 'No groups found under this parent with a missing group identifier.', 'uncanny-pro-toolkit' ) . '</p>';
        }
        
        // Always output full table with identifier column
        ob_start();
        echo '<h2>' . esc_html__( 'Groups With Missing Identifiers', 'uncanny-pro-toolkit' ) . '</h2>';
        echo '<table class="widefat fixed striped"><thead><tr>' .
             '<th>' . esc_html__( 'Group Name', 'uncanny-pro-toolkit' ) . '</th>' .
             '<th>' . esc_html__( 'Group ID', 'uncanny-pro-toolkit' ) . '</th>' .
             '<th>' . esc_html__( 'Group Parent ID', 'uncanny-pro-toolkit' ) . '</th>' .
             '<th>' . esc_html__( 'Group Identifier', 'uncanny-pro-toolkit' ) . '</th>' .
             '</tr></thead><tbody>';
        foreach ( $rows as $r ) {
            printf(
                '<tr><td>%s</td><td>%d</td><td>%d</td><td>%s</td></tr>',
                esc_html( $r['name'] ),
                esc_html( $r['id'] ),
                esc_html( $r['parent'] ),
                esc_html( $r['identifier'] )
            );
        }
        echo '</tbody></table>';
        self::$report_html .= ob_get_clean();
    }

    // Recursively get all child group IDs
    protected static function get_all_child_groups( $parent_id ) {
        $children = get_posts( array(
            'post_type'   => 'groups',
            'post_parent' => $parent_id,
            'numberposts' => -1,
            'post_status' => 'publish',
            'fields'      => 'ids',
        ) );
        $all = array();
        foreach ( $children as $cid ) {
            $all[] = $cid;
            $all   = array_merge( $all, self::get_all_child_groups( $cid ) );
        }
        return $all;
    }

    // Add metabox to group edit screen
    public static function uo_add_meta_box() {
        add_meta_box(
            'uncanny_import_group_identifier',
            sprintf(
                esc_html_x( '%s Identifier', 'placeholder: Group', 'uncanny-pro-toolkit' ),
                learndash_get_custom_label( 'group' )
            ),
            [ __CLASS__, 'uncanny_group_identifier_metabox_content' ],
            learndash_get_post_type_slug( 'group' ),
            'side'
        );
    }

    // Metabox content HTML
    public static function uncanny_group_identifier_metabox_content( $post ) {
        if ( $post->post_type === 'groups' ) {
            $identifier = get_post_meta( $post->ID, self::$group_identifier_key, true );
            wp_nonce_field( 'uo-group-identifier-metabox-nonce', 'uo-group-identifier-metabox-nonce' );
            ?>
            <p><label for="<?php echo self::$group_identifier_key; ?>"><?php esc_html_e( 'Group Identifier', 'uncanny-pro-toolkit' ); ?></label></p>
            <input name="<?php echo self::$group_identifier_key; ?>" type="text" id="<?php echo self::$group_identifier_key; ?>" value="<?php echo esc_attr( $identifier ); ?>" />
            <?php
        }
    }

    // Save metabox data
    public static function save_post( $post_id, $post, $update ) {
        if ( isset( $_POST['uo-group-identifier-metabox-nonce'] ) && wp_verify_nonce( $_POST['uo-group-identifier-metabox-nonce'], 'uo-group-identifier-metabox-nonce' ) ) {
            if ( isset( $_POST[ self::$group_identifier_key ] ) ) {
                update_post_meta( $post_id, self::$group_identifier_key, sanitize_key( $_POST[ self::$group_identifier_key ] ) );
            }
        }
    }
}
