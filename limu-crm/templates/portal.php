<?php
/**
 * Isolated, accessible Hebrew CRM portal template.
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
<main id="main" class="login-shell"><section class="login-card"><a class="brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">limu<span>CRM</span></a><p class="eyebrow">מוסדות. פניות. תמונה מלאה.</p><h1>ברוכים הבאים</h1><p>התחברו למרחב הניהול שלכם</p>
	<?php
	if ( ! empty( $GLOBALS['lcrm_login_error'] ) ) :
		?>
	<p role="alert"><?php echo esc_html( $GLOBALS['lcrm_login_error'] ); ?></p>
		<?php
	endif;
	?>
<form method="post" action="<?php echo esc_url( home_url( '/crm/' ) ); ?>">
	<?php wp_nonce_field( 'lcrm_login', '_lcrm_login_nonce' ); ?>
<p><label for="user_login">שם משתמש או כתובת אימייל</label><input id="user_login" name="log" autocomplete="username" required maxlength="254"></p>
<p><label for="user_pass">סיסמה</label><input id="user_pass" name="pwd" type="password" autocomplete="current-password" required></p>
	<?php echo turnstile_widget( 'crm_login', 'cf-turnstile-response' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Widget escapes all attributes internally. ?>
<p><button class="button primary" type="submit">כניסה למערכת</button></p>
</form><a href="<?php echo esc_url( wp_lostpassword_url( home_url( '/crm/' ) ) ); ?>">שכחתם את הסיסמה?</a></section></main>
<?php elseif ( ! can_view() ) : ?>
<main id="main" class="login-shell"><section class="login-card"><h1>אין הרשאה למערכת</h1><p>יש לפנות למנהל האתר לקבלת גישה למוסד שלכם.</p><a href="<?php echo esc_url( wp_logout_url( home_url( '/crm/' ) ) ); ?>">התנתקות</a></section></main>
<?php else : ?>
<div class="app-shell"><aside class="sidebar" aria-label="ניווט ראשי"><a class="brand" href="<?php echo esc_url( home_url( '/crm/' ) ); ?>">limu<span>CRM</span></a><p class="sidebar-caption">מרחב העבודה שלך</p><nav id="navigation"><button data-view="dashboard" aria-current="page"><span aria-hidden="true">◈</span> <span>סקירה כללית</span></button><button data-view="deliveries"><span aria-hidden="true">◎</span> <span>מרכז לידים</span></button><button data-view="bills"><span aria-hidden="true">▤</span> <span>חיובים ותשלומים</span></button><button data-view="reports"><span aria-hidden="true">↗</span> <span>דוחות</span></button>
	<?php
	if ( manager() ) :
		?>
	<button data-view="institutions"><span aria-hidden="true">▦</span> <span>מוסדות ותעריפים</span></button><button data-view="settings"><span aria-hidden="true">⚙</span> <span>הגדרות והרשאות</span></button><button data-view="audit"><span aria-hidden="true">◷</span> <span>יומן פעילות</span></button>
	<?php endif; ?></nav><div class="sidebar-bottom"><span class="avatar" aria-hidden="true">ל</span><div><strong><?php echo esc_html( wp_get_current_user()->display_name ); ?></strong><small><?php echo manager() ? 'מנהל מערכת' : 'נציג מוסד · צפייה בלבד'; ?></small></div><a href="<?php echo esc_url( wp_logout_url( home_url( '/crm/' ) ) ); ?>">יציאה</a></div></aside>
<div class="workspace"><header class="topbar"><span>לימו <span aria-hidden="true">/</span> <strong id="breadcrumb">סקירה כללית</strong></span><span class="private-label">מרחב פרטי ומאובטח</span></header><main id="main" tabindex="-1"><div id="notification" role="status" aria-live="polite"></div><div id="screen" aria-busy="true"><p class="loading">טוען את סביבת העבודה…</p></div></main><footer>עיצוב ופיתוח <a href="https://m-d.co.il" rel="noopener" target="_blank">Multi Digital</a> · Limu CRM</footer></div></div>
<dialog id="modal" aria-labelledby="modal-title"><button id="close-modal" class="icon-button" aria-label="סגירת חלון">×</button><div id="modal-content"></div></dialog>
<?php endif; ?>
<?php
if ( ! can_view() ) :
	?>
	<footer>עיצוב ופיתוח <a href="https://m-d.co.il" rel="noopener" target="_blank">Multi Digital</a> · Limu CRM</footer>
	<?php
endif;
?>

<?php $accessibility_page = get_page_by_path( 'נגישות' ); ?>
<?php
if ( $accessibility_page && 'publish' === $accessibility_page->post_status ) :
	?>
	<p class="portal-policy"><a href="<?php echo esc_url( get_permalink( $accessibility_page ) ); ?>">הצהרת נגישות</a></p>
	<?php
endif;
?>
<details class="access-tools" id="access-tools"><summary>נגישות ותצוגה</summary><section aria-label="העדפות נגישות"><h2>התאמת תצוגה</h2><label for="access-size">גודל טקסט</label><input id="access-size" type="range" min="100" max="150" step="10" value="100"><p class="muted">אפשר גם להגדיל בדפדפן עד 200% ומעלה.</p>
<?php
foreach ( array(
	'contrast' => 'ניגודיות גבוהה',
	'invert'   => 'היפוך צבעים',
	'gray'     => 'גווני אפור',
	'links'    => 'הדגשת קישורים',
	'font'     => 'גופן פשוט',
	'motion'   => 'עצירת אנימציות',
	'headings' => 'הדגשת כותרות',
	'guide'    => 'מדריך קריאה',
) as $key => $label ) :
	?>
<label class="check-label"><input type="checkbox" data-access="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
<?php endforeach; ?>
<p class="muted">ניווט במקלדת: Tab להתקדמות, Shift+Tab לחזרה ו־Escape לסגירת חלון.</p><button type="button" class="button" id="access-reset">איפוס העדפות</button></section></details>
<div id="reading-guide" aria-hidden="true" hidden></div>
<aside id="privacy-notice" class="privacy-notice" aria-labelledby="privacy-title" hidden><h2 id="privacy-title">פרטיות במרחב הניהול</h2><p>המערכת משתמשת בעוגיות להתחברות ובהעדפות תצוגה הנשמרות בדפדפן.</p>
<?php
if ( get_privacy_policy_url() ) :
	?>
	<a href="<?php echo esc_url( get_privacy_policy_url() ); ?>">מדיניות הפרטיות</a>
	<?php
endif;
?>
<button type="button" class="button" id="privacy-ack">הבנתי</button></aside>
<?php
wp_print_scripts( array( 'limu-crm-access' ) );
if ( turnstile_site_key() ) {
	wp_print_scripts( array( 'lcrm-turnstile' ) );
}
if ( can_view() ) {
	wp_print_scripts( array( 'limu-crm' ) );
}
?>
</body></html>
