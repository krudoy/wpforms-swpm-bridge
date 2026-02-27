<?php

declare(strict_types=1);

namespace SWPMWPForms\Handlers;

/**
 * Processes do_shortcode() on WPForms HTML/content field content at frontend render time.
 *
 * Security guards enforced:
 *   (1) Per-form gate  — reads options.enable_shortcodes from form settings; exits immediately if false/absent.
 *   (2) Field-type whitelist — only 'html' and 'content' field types are touched; all others are left unchanged.
 *   (3) Source whitelist — content is sourced from $form_data['fields'][$id]['code'] (html) or ['content'] (content),
 *       which are builder-authored values stored in post_content. $_POST, $_GET, and submission entry values
 *       are never passed through do_shortcode() by this handler.
 *
 * Hook: wpforms_frontend_form_data (filter)
 * Fires in WPForms\Pro\Frontend\Frontend::output() / WPForms_Frontend::output() before the field-render loop.
 * Signature: apply_filters( 'wpforms_frontend_form_data', array $form_data ): array
 *
 * NOTE: WPForms source is not bundled in this workspace. Hook name verified against
 *       WPForms Lite public repository (src/Frontend/Frontend.php, class WPForms_Frontend).
 *       If the installed WPForms version does not call this filter, this handler silently
 *       no-ops — no errors, no side effects.
 */
class ShortcodeDisplayHandler {

    /**
     * Register hooks. Called outside is_admin() so it fires on the frontend.
     */
    public function init(): void {
        add_filter( 'wpforms_frontend_form_data', [ $this, 'processHtmlFieldShortcodes' ] );
    }

    /**
     * Apply do_shortcode() to HTML/content field builder content when the per-form toggle is ON.
     *
     * @param array $formData WPForms form data (fields, settings, …).
     * @return array Modified form data with shortcodes evaluated in qualifying fields.
     */
    public function processHtmlFieldShortcodes( array $formData ): array {
        // Guard (1): Per-form gate — must be explicitly opted in; default is OFF.
        $config = $formData['settings']['swpm_integration'] ?? [];
        if ( empty( $config['options']['enable_shortcodes'] ) ) {
            return $formData;
        }

        if ( empty( $formData['fields'] ) || ! is_array( $formData['fields'] ) ) {
            return $formData;
        }

        foreach ( $formData['fields'] as $id => $field ) {
            $type = $field['type'] ?? '';

            if ( $type === 'html' ) {
                // Guard (2)+(3): HTML field type; content from builder-authored 'code' property only.
                if ( ! empty( $field['code'] ) && is_string( $field['code'] ) ) {
                    $formData['fields'][ $id ]['code'] = do_shortcode( $field['code'] );
                }
            } elseif ( $type === 'content' ) {
                // Guard (2)+(3): Content field type (Pro); content from builder-authored 'content' property only.
                if ( ! empty( $field['content'] ) && is_string( $field['content'] ) ) {
                    $formData['fields'][ $id ]['content'] = do_shortcode( $field['content'] );
                }
            }
            // All other field types: no action taken.
        }

        return $formData;
    }
}