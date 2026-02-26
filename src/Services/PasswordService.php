<?php

declare(strict_types=1);

namespace SWPMWPForms\Services;

/**
 * Password generation and email delivery service.
 */
class PasswordService {
    
    private static ?PasswordService $instance = null;
    private Logger $logger;
    
    public static function instance(): PasswordService {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->logger = Logger::instance();
    }
    
    /**
     * Generate a secure random password.
     */
    public function generate(int $length = 16): string {
        return wp_generate_password($length, true, false);
    }
    
    /**
     * Send password to user via email.
     * 
     * @return bool Whether the email was sent successfully.
     */
    public function sendPasswordEmail(string $email, string $password, array $context = []): bool {
        $username = $context['username'] ?? '';
        $siteName = get_bloginfo('name');
        $loginUrl = wp_login_url();
        
        // Check for SWPM login page
        if (class_exists('SwpmSettings')) {
            $settings = \SwpmSettings::get_instance();
            $swpmLoginPage = $settings->get_value('login-page-url');
            if (!empty($swpmLoginPage)) {
                $loginUrl = $swpmLoginPage;
            }
        }
        
        $subject = sprintf(
            /* translators: %s: site name */
            __('Your account details for %s', 'wpforms-swpm-bridge'),
            $siteName
        );
        
        $message = sprintf(
            /* translators: 1: username, 2: password, 3: login URL, 4: site name */
            __(
                "Hello,\n\nYour membership account has been created.\n\n" .
                "Username: %1\$s\n" .
                "Password: %2\$s\n\n" .
                "Login here: %3\$s\n\n" .
                "We recommend changing your password after logging in.\n\n" .
                "Thanks,\n%4\$s",
                'wpforms-swpm-bridge'
            ),
            $username,
            $password,
            $loginUrl,
            $siteName
        );
        
        /**
         * Filter the password email subject.
         * 
         * @param string $subject Email subject.
         * @param string $email Recipient email.
         * @param array $context Additional context.
         */
        $subject = apply_filters('swpm_wpforms_password_email_subject', $subject, $email, $context);
        
        /**
         * Filter the password email message.
         * 
         * @param string $message Email message.
         * @param string $email Recipient email.
         * @param string $password The generated password.
         * @param array $context Additional context.
         */
        $message = apply_filters('swpm_wpforms_password_email_message', $message, $email, $password, $context);
        
        $headers = ['Content-Type: text/plain; charset=UTF-8'];
        
        $sent = wp_mail($email, $subject, $message, $headers);
        
        if ($sent) {
            $this->logger->info('Password email sent', ['email' => $email]);
            
            /**
             * Fires after a password is auto-generated and emailed.
             * 
             * @param string $email Recipient email.
             * @param array $context Additional context.
             */
            do_action('swpm_wpforms_password_generated', $email, $context);
        } else {
            $this->logger->error('Failed to send password email', ['email' => $email]);
        }
        
        return $sent;
    }
}