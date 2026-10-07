# אימות גרסת 0.1.1

נבדקה סביבת פיתוח מבודדת: PHP 7.4.33 ו־PHP 8.4.26, WordPress 7.1.3 (מקור WordPress הרשמי), MariaDB 11.8.6, Chromium, Playwright 1.62.1 ו־axe-core 4.10.3. הנתונים שנוצרו לבדיקות פונקציונליות ולצילומים הם נתוני דמה בלבד. הגיבוי המקורי לא הותקן כאתר חי ולא נשלחו לידים, מיילים או מסמכים.

## תוצאות

- **52 assertions פונקציונליות עברו בכל אחת משתי סביבות PHP** על WordPress ומסד נתונים אמיתיים: כללי כפילות, חיוב לכל מוסד, היסטוריה לא לחיוב, הסכמים, אישור מרובה, תאריך פירעון, תשלומים, מניעת ניסיון חוזר, שגיאת שמירה ו־rollback, הרשאות, קלט פגום, מיפוי חריגים ו־XLSX.
- **20 בדיקות דפדפן ונגישות עברו בכל אחת משתי סביבות PHP**: כניסה למנהל ולמוסד, מסכים, חלונות, Escape, נייד, ניגודיות גבוהה וחסימת כתיבה של מוסד דרך cookie-authenticated REST.
- **55 בדיקות רגרסיה לאבטחה ולכספים**, **13 בדיקות הגנת התחברות** ו־**26 בדיקות קליטה אוטומטית** עברו בשתי הסביבות. יחד עם הבדיקה הפונקציונלית: **146 assertions לכל גרסת PHP**.
- **15 בדיקות frontend נוספות** עברו מול שתי הסביבות עם תגובות API מדומות וכתיבות מדומות: פלט HTML זדוני, ללא אישור פניות ידני, PDF לידים, תעריפים עתידיים, שמירה/רענון, חלונות והתחברות שפגה.
- **שתי בקשות תשלום מקבילות** בשתי סביבות PHP הוחזרו עם אותו מזהה: נוצר תשלום אחד והיתרה השתנתה פעם אחת בלבד.
- WordPress Coding Standards עם כללי סגנון נוספים: **אפס שגיאות ואפס אזהרות** בריצה האחרונה. חריגים נקודתיים עם הסברים מפורשים מתועדים בקוד וב־README.
- PHP lint לכל קובצי התוסף ובדיקת תחביר JS עברו. הנכסים המקומיים נבנו ומוקטנו באמצעות `npm ci` ו־`npm run build`.
- בריצות axe לא נמצאו violations בכללי WCAG A/AA שנבדקו על המסכים והחלונות. אין טענה שהבדיקה מחליפה בדיקה ידנית עם קורא מסך או אישור נגישות.
- בדיקת מיפוי מול הגיבוי המקורי: 3,860 רשומות ניתנות לשיוך מדויק, עם 4,008 שיוכי מוסד; 66 רשומות משויכות לכמה מוסדות. 760 רשומות בעלות ערך מוסד לא ממופה ועוד שתיים ללא מטא מוסד. זו בדיקת מיפוי בלבד, ללא אישור מסירה או חיוב.

לוגי התוצאות וצילומי הדמה נמצאים ב־`artifacts/`.

## הרצה חוזרת בסביבת Codex הנוכחית

הכלים ומסד הדמה נמצאים מחוץ למאגר ב־`/workspace/.limu-tools`. הפעלת `/workspace/.limu-tools/start.sh` מעלה רק את שירותי הבדיקות המקומיים, אם אינם רצים, ומאמתת את מסך ההתחברות. MariaDB מוגבל ל־Unix socket ושרתי PHP ל־loopback בלבד: PHP 8.4 בפורט 8090 ו־PHP 7.4 בפורט 8091. ניתן להחליף php74 ב־php ו־LIMU_TEST_URL ל־8090 להרצה ב־PHP העדכני. הסקריפט אינו עוצר שירותים אחרים.

```sh
/workspace/.limu-tools/start.sh
cd /workspace/limu
npm ci
npm run build
LIMU_WP_ROOT=/workspace/.limu-tools/wordpress /workspace/.limu-tools/php74 tests/integration.php
LIMU_WP_ROOT=/workspace/.limu-tools/wordpress /workspace/.limu-tools/php74 tests/security-review.php
LIMU_WP_ROOT=/workspace/.limu-tools/wordpress /workspace/.limu-tools/php74 tests/auth-security.php
LIMU_WP_ROOT=/workspace/.limu-tools/wordpress /workspace/.limu-tools/php74 tests/native-capture.php
LIMU_PHP=/workspace/.limu-tools/php74 LIMU_WP_ROOT=/workspace/.limu-tools/wordpress python3 tests/concurrency.py
/workspace/.limu-tools/php /workspace/.limu-tools/member-login.php
LIMU_TEST_URL=http://127.0.0.1:8091 LIMU_TEST_LOGIN=/workspace/.limu-tools/test-login.json LIMU_MEMBER_LOGIN=/workspace/.limu-tools/member-login.json LIMU_AXE_PATH=/workspace/limu/node_modules/axe-core/axe.min.js LIMU_SCREENSHOT_DIR=/workspace/limu/artifacts node tests/browser.cjs
/workspace/.limu-tools/php74 /workspace/.limu-tools/phpcs/bin/phpcs --standard=phpcs.xml
```

הבדיקה הפונקציונלית מוחקת את רשומות הדמה במסד `limu_test`. אין לשנות את ההגנה או להפנות אותה למסד הייצור. קובצי ההתחברות לבדיקות מוגנים בהרשאות 0600 ונמצאים מחוץ ל־Git; אין להעתיק או להדפיס את ערכיהם.

כלי PHPCS/WPCS ששימשו להרצה נקראו מקוד המקור הרשמי: PHPCS commit `7f47350339831578dfab3eb288c12d18c1dfccfe`, WPCS commit `87988814d50a0d560ed89bf338300f85cbfb23a9`.

## מה טרם אומת

- Elementor Pro, JetFormBuilder והשליחה בפועל באתר המקורי: קובץ התוספים חורג ממגבלת העברה של 32MB. קוד התבנית הרלוונטי נקרא; חיבור רשומות מקור ו־HTTP נבדק עם fixtures, ללא הפעלת השליחה המקורית. יש לבצע בדיקת טופס אמיתי ב־staging. אין דרישה לאישור ידני לכל פנייה.
- iCount נדחה במפורש לשלב האחרון. אין מימוש API, דרישות חיצוניות, חשבוניות או קבלות בגרסה זו.
- Turnstile נבדק עם תגובות ספק מדומות, כולל כשל טוקן/action ושמירת TLS. לא בוצעה בדיקה מול חשבון Cloudflare פעיל.
- לא אומתו PageSpeed/Core Web Vitals באתר החי, תוספי הקאש, SMTP, כל גרסאות WordPress/PHP המינימליות, restore של גיבוי הייצור או restoration במשימת Codex חדשה.
- לא בוצעו התקנה/פרסום באתר החי או פרסום snapshot של סביבת Codex. גרסת 0.1.0 הועלתה ל־GitHub; הקוד ואריזות 0.1.1 נכללים בעדכון המאגר של סקירה זו.

## אימות PHP 7.4.33

הריצה בוצעה בבינארי PHP 7.4.33 אמיתי עם mysqli, JSON, XML, ctype, mbstring, tokenizer ו־ZipArchive. החבילות המקומיות אומתו באמצעות חתימת Debian וה־SHA256 של האינדקסים ושל החבילות. זהו runtime לבדיקת תאימות בלבד, לא המלצה להתקנת חבילת PHP ישנה על הייצור. ראיות אימות נשמרו מחוץ ל־Git בסביבת הכלים.

בסיום הריצות האחרונות לא נרשמו שגיאות או אזהרות ביומני PHP של שתי הסביבות. לוגים קודמים של פיתוח וכשלי setup נשמרו בנפרד מחוץ למאגר, ולא הוצגו כאימות שעבר.

ה־patch לתבנית הבת נבדק ב־dry-run מול העותק שהתקבל בגיבוי; הוא לא הותקן ולא נבדקה תאימות מלאה של התבנית ל־PHP 8. דוח הסקירה: `SECURITY_REVIEW.md`.
