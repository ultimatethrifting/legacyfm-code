<?php
/**
 * Plugin Name: Automator LearnDash Quiz Course Tokens
 * Plugin URI:  https://ultimatethrifting.com/
 * Description: Adds "Associated course ID" and "Associated course title" tokens to the "A user passes a quiz" and "A user fails a quiz" triggers in Uncanny Automator.
 * Version:     4.1.1
 * Author:      Ultimate Thrifting
 * License:     GPL2
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register three custom tokens on the LearnDash "pass quiz" and "fail quiz" triggers (trigger code LD_PASSQUIZ).
 */
function uat_register_quiz_course_tokens( $tokens, $args ) {
    // Only target the LearnDash pass-quiz and fail-quiz triggers
    if ( empty( $args['triggers_meta']['code'] ) || ! in_array( $args['triggers_meta']['code'], array( 'LD_PASSQUIZ', 'LD_FAILQUIZ' ), true ) ) {
        return $tokens;
    }

    // The trigger_meta identifier for this integration
    $identifier = 'LDQUIZ';

    // Raw quiz ID for testing
    $tokens[] = array(
        'tokenId'         => "{$identifier}_TEST_QUIZ_ID",
        'tokenName'       => __( 'Test quiz ID', 'automator-learndash' ),
        'tokenType'       => 'int',
        'tokenIdentifier' => $identifier,
    );

    // Associated course ID
    $tokens[] = array(
        'tokenId'         => "{$identifier}_COURSE_ID",
        'tokenName'       => __( 'Associated course ID', 'automator-learndash' ),
        'tokenType'       => 'int',
        'tokenIdentifier' => $identifier,
    );

    // Associated course title
    $tokens[] = array(
        'tokenId'         => "{$identifier}_COURSE_TITLE",
        'tokenName'       => __( 'Associated course title', 'automator-learndash' ),
        'tokenType'       => 'text',
        'tokenIdentifier' => $identifier,
    );

    return $tokens;
}
add_filter( 'automator_maybe_trigger_ld_ldquiz_tokens',      'uat_register_quiz_course_tokens', 9999, 2 );
add_filter( 'automator_maybe_trigger_ld_ldfailquiz_tokens',  'uat_register_quiz_course_tokens', 9999, 2 );

/**
 * Parse our custom quiz-course tokens at execution time, replicating native Quiz ID lookup.
 * Signature: ( $value, $pieces, $recipe_id, $trigger_data, $user_id, $replace_args )
 */
function uat_parse_quiz_course_tokens( $value, $pieces, $recipe_id, $trigger_data, $user_id, $replace_args ) {
    // Only handle our LDQUIZ tokens
    if ( empty( $pieces[1] ) || 'LDQUIZ' !== $pieces[1] || empty( $pieces[2] ) ) {
        return $value;
    }
    $token = $pieces[2];

    // Supported custom tokens
    $supported = array(
        'LDQUIZ_TEST_QUIZ_ID',
        'LDQUIZ_COURSE_ID',
        'LDQUIZ_COURSE_TITLE',
    );
    if ( ! in_array( $token, $supported, true ) ) {
        return $value;
    }

    // Native stores raw quiz ID under Automator token table key 'LDQUIZ'
    $quiz_id = intval( Automator()->db->token->get( 'LDQUIZ', $replace_args ) );
    // Fallback: trigger meta (ignore -1)
    if ( empty( $quiz_id ) ) {
        $trigger = reset( $trigger_data );
        $meta    = isset( $trigger['meta'] ) ? $trigger['meta'] : array();
        if ( ! empty( $meta['LDQUIZ'] ) && intval( $meta['LDQUIZ'] ) !== -1 ) {
            $quiz_id = absint( $meta['LDQUIZ'] );
        }
    }
    if ( empty( $quiz_id ) ) {
        return $value;
    }

    // Return raw quiz ID for test token
    if ( 'LDQUIZ_TEST_QUIZ_ID' === $token ) {
        return $quiz_id;
    }

    // From here, retrieve course via LearnDash
    if ( ! function_exists( 'learndash_get_course_id' ) ) {
        return $value;
    }
    $course_id = intval( learndash_get_course_id( $quiz_id ) );
    if ( empty( $course_id ) ) {
        return $value;
    }

    if ( 'LDQUIZ_COURSE_ID' === $token ) {
        return $course_id;
    }
    if ( 'LDQUIZ_COURSE_TITLE' === $token ) {
        return get_post_field( 'post_title', $course_id );
    }

    return $value;
}
add_filter( 'automator_maybe_parse_token', 'uat_parse_quiz_course_tokens', 20, 6 );
