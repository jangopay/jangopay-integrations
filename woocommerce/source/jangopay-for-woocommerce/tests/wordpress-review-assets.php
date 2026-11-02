<?php
/** Offline WP asset + admin notice scope checks. WP functions are simulated. */
require __DIR__.'/fixtures/review-bootstrap.php';
function current_user_can($cap) {return $cap==='manage_woocommerce';}
function wp_unslash($value) {return stripslashes($value);}
function sanitize_key($value) {return strtolower(preg_replace('/[^a-z0-9_\-]/','',(string)$value));}
function wp_create_nonce($name) {return 'nonce-for-'.$name;}
function admin_url($path) {return 'https://test.local/wp-admin/'.$path;}
function esc_html($value) {return htmlspecialchars((string)$value, ENT_QUOTES,'UTF-8');}
function get_option($name,$default=[]) {return ['enabled'=>'no'];}
// Keep the test on the actual plugin controller and its WordPress API calls.
JPWC_Controller::adminAssets('index.php');
verify('no admin JS on generic dashboard',empty($GLOBALS['jpwc_test_scripts']['jpwc-admin']));
ob_start(); JPWC_Controller::notices(); $notice=ob_get_clean();
verify('no JangoPay notice on generic dashboard',$notice==='');
$_GET=['page'=>'wc-settings','tab'=>'checkout','section'=>'jangopay_woocommerce'];
JPWC_Controller::adminAssets('woocommerce_page_wc-settings');
verify('JS enqueued on JangoPay gateway settings',isset($GLOBALS['jpwc_test_scripts']['jpwc-admin']));
verify('admin JS path points to packaged file',str_contains($GLOBALS['jpwc_test_scripts']['jpwc-admin']['src'],'assets/js/admin.js'));
verify('admin nonce and ajax URL injected via WP API',str_contains(implode('',$GLOBALS['jpwc_test_inline']['jpwc-admin']['before']??[]),'nonce-for-jpwc_connection'));
ob_start(); JPWC_Controller::notices(); $notice=ob_get_clean();
verify('gateway notice appears on relevant settings screen',str_contains($notice,'jpwc-test'));
verify('settings notice has no direct script/style blocks',!str_contains($notice,'<script')&&!str_contains($notice,'<style'));
$controller=file_get_contents(dirname(__DIR__).'/includes/class-jpwc-controller.php');
verify('PHP does not output raw script or style blocks',!preg_match('/<\s*(script|style)\b/i',$controller));
verify('gateway checkout uses registered stylesheet',str_contains($controller,"wp_enqueue_style('jpwc-checkout'"));
verify('gateway checkout uses registered JavaScript',str_contains($controller,"wp_enqueue_script('jpwc-checkout'"));
verify('isolated checkout prints styles and footer scripts',str_contains($controller,"wp_print_styles('jpwc-checkout')")&&str_contains($controller,'wp_print_footer_scripts()'));
verify('frontend and admin JS assets exist',is_file(dirname(__DIR__).'/assets/js/checkout.js')&&is_file(dirname(__DIR__).'/assets/js/admin.js'));
verify('checkout stylesheet exists',is_file(dirname(__DIR__).'/assets/css/checkout.css'));
