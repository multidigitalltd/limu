<?php
/**
 * Isolated Hebrew CRM portal template.
 *
 * @package LimuCRM
 */

namespace LimuCRM;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!doctype html>
<html lang="he" dir="rtl"><head><meta charset="<?php echo esc_attr( get_bloginfo( 'charset' ) ); ?>"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Limu CRM — ניהול מוסדות ולידים</title><?php wp_print_styles( array( 'limu-crm' ) ); ?></head>
<body class="lcrm"><a class="skip-link" href="#main">דילוג לתוכן</a>
<?php if ( ! is_user_logged_in() ) : ?>
<main id="main" class="login-shell">
<section class="login-intro" aria-label="Limu CRM">
	<a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>"><span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 32 32"><path d="M9 7v18h15M15 7v12h9"></path></svg></span><span class="brand-name">limu<span>CRM</span></span></a>
	<div class="login-intro-copy"><span class="login-kicker">מרחב הניהול שלך</span><h2>כל המוסדות.<br>כל הפניות.<br><span>תמונה אחת ברורה.</span></h2><p>הלידים, החיובים והתשלומים שלכם —<br>במקום אחד.</p></div>
	<div class="login-graphic" aria-hidden="true"><div class="login-graphic-head"><span></span><span></span><span></span></div><div class="login-graphic-cards"><i></i><i></i><i></i></div><div class="login-graphic-bars"><i></i><i></i><i></i><i></i><i></i><i></i></div></div>
</section>
<section class="login-card">
	<a class="brand login-brand" href="<?php echo esc_url( home_url( '/' ) ); ?>"><span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 32 32"><path d="M9 7v18h15M15 7v12h9"></path></svg></span><span class="brand-name">limu<span>CRM</span></span></a>
	<p class="eyebrow">כניסה למרחב הניהול</p><h1>ברוכים הבאים</h1><p class="muted">התחברו כדי להמשיך לסביבת העבודה שלכם.</p>
	<?php if ( ! empty( $GLOBALS['lcrm_login_error'] ) ) : ?>
		<p class="login-error" role="alert"><?php echo esc_html( $GLOBALS['lcrm_login_error'] ); ?></p>
	<?php endif; ?>
	<form method="post" action="<?php echo esc_url( home_url( '/crm/' ) ); ?>">
		<?php wp_nonce_field( 'lcrm_login', '_lcrm_login_nonce' ); ?>
		<p><label for="user_login">שם משתמש או כתובת אימייל</label><input id="user_login" name="log" autocomplete="username" required maxlength="254"></p>
		<p><label for="user_pass">סיסמה</label><input id="user_pass" name="pwd" type="password" autocomplete="current-password" required></p>
		<?php echo turnstile_widget( 'crm_login', 'cf-turnstile-response' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Widget escapes all attributes internally. ?>
		<p><button class="button primary" type="submit">כניסה למערכת <span aria-hidden="true">←</span></button></p>
	</form>
	<a class="forgot-password" href="<?php echo esc_url( wp_lostpassword_url( home_url( '/crm/' ) ) ); ?>">שכחתם את הסיסמה?</a>
</section></main>
<?php elseif ( ! can_view() ) : ?>
<main id="main" class="login-shell denied-shell"><section class="login-card"><p class="eyebrow">LIMU CRM</p><h1>אין הרשאה למערכת</h1><p class="muted">יש לפנות למנהל האתר לקבלת גישה למוסד שלכם.</p><a class="button" href="<?php echo esc_url( wp_logout_url( home_url( '/crm/' ) ) ); ?>">התנתקות</a></section></main>
<?php else : ?>
	<?php
	$nav_items = array(
		'dashboard'  => array(
			'label' => 'סקירה כללית',
			'path'  => 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
		),
		'deliveries' => array(
			'label' => 'מרכז לידים',
			'path'  => 'M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2 M20 21v-2a4 4 0 0 0-3-3.87 M13 3.13a4 4 0 0 1 0 7.75 M13 7a4 4 0 1 1-8 0a4 4 0 0 1 8 0z',
		),
		'bills'      => array(
			'label' => 'חיובים ותשלומים',
			'path'  => 'M6 3h12v18l-3-2-3 2-3-2-3 2V3z M9 7h6 M9 11h6 M9 15h3',
		),
		'reports'    => array(
			'label' => 'דוחות',
			'path'  => 'M4 3v18h17 M8 16v-5 M13 16V7 M18 16V4',
		),
	);
	if ( manager() ) {
		$nav_items['institutions'] = array(
			'label' => 'מוסדות ותעריפים',
			'path'  => 'M3 21h18 M5 21V7l7-4 7 4v14 M9 21v-5h6v5 M9 8h1 M14 8h1 M9 12h1 M14 12h1',
		);
		$nav_items['settings']     = array(
			'label' => 'הגדרות והרשאות',
			'path'  => 'M4 7h16 M4 17h16 M8 4v6 M16 14v6',
		);
		$nav_items['audit']        = array(
			'label' => 'יומן פעילות',
			'path'  => 'M3 12a9 9 0 1 0 3-6.7 M3 3v5h5 M12 7v5l3 2',
		);
	}
	?>
<div class="app-shell">
<aside class="sidebar" aria-label="ניווט ראשי">
	<a class="brand" href="<?php echo esc_url( home_url( '/crm/' ) ); ?>"><span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 32 32"><path d="M9 7v18h15M15 7v12h9"></path></svg></span><span class="brand-name">limu<span>CRM</span></span></a>
	<p class="sidebar-caption">מרחב הניהול</p>
	<nav id="navigation" aria-label="מסכי המערכת">
		<?php foreach ( $nav_items as $nav_key => $nav_item ) : ?>
			<button type="button" data-view="<?php echo esc_attr( $nav_key ); ?>" <?php echo 'dashboard' === $nav_key ? 'aria-current="page"' : ''; ?>><span class="nav-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="<?php echo esc_attr( $nav_item['path'] ); ?>"></path></svg></span><span><?php echo esc_html( $nav_item['label'] ); ?></span></button>
		<?php endforeach; ?>
	</nav>
	<div class="sidebar-bottom"><span class="avatar" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M20 21v-2a7 7 0 0 0-14 0v2 M17 7a4 4 0 1 1-8 0a4 4 0 0 1 8 0z"></path></svg></span><div class="user-identity"><strong><?php echo esc_html( wp_get_current_user()->display_name ); ?></strong><small><?php echo manager() ? 'מנהל מערכת' : 'נציג מוסד · צפייה בלבד'; ?></small></div><a class="logout-link" href="<?php echo esc_url( wp_logout_url( home_url( '/crm/' ) ) ); ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 5H5v14h4 M14 8l4 4-4 4 M9 12h9"></path></svg><span>יציאה</span></a></div>
</aside>
<div class="workspace">
	<header class="topbar"><span class="breadcrumb"><span class="breadcrumb-root" dir="ltr">Limu CRM</span><span class="breadcrumb-separator" aria-hidden="true">/</span><strong id="breadcrumb">סקירה כללית</strong></span></header>
	<main id="main" tabindex="-1"><div id="notification" role="status" aria-live="polite"></div><div id="screen" aria-busy="true"><p class="loading">טוען את סביבת העבודה…</p></div></main>
</div></div>
<dialog id="modal" aria-labelledby="modal-title"><button id="close-modal" type="button" class="icon-button" aria-label="סגירת חלון"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12 M18 6L6 18"></path></svg></button><div id="modal-content"></div></dialog>
<?php endif; ?>
<aside id="privacy-notice" class="privacy-notice" aria-labelledby="privacy-title" hidden><h2 id="privacy-title">פרטיות במרחב הניהול</h2><p>המערכת משתמשת בעוגיות לצורך התחברות מאובטחת.</p>
<?php if ( get_privacy_policy_url() ) : ?>
	<a href="<?php echo esc_url( get_privacy_policy_url() ); ?>">מדיניות הפרטיות</a>
<?php endif; ?>
<button type="button" class="button" id="privacy-ack">הבנתי</button></aside>
<?php
wp_print_scripts( array( 'limu-crm-privacy' ) );
if ( turnstile_site_key() ) {
	wp_print_scripts( array( 'lcrm-turnstile' ) );
}
if ( can_view() ) {
	wp_print_scripts( array( 'limu-crm' ) );
}
?>
</body></html>
