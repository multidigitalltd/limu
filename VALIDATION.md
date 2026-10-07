# אימות גרסת 0.1.3

נבדקה סביבת פיתוח מבודדת: PHP 7.4.33 ו־PHP 8.4.26, WordPress 7.1.3 (מקור WordPress הרשמי), MariaDB 11.8.6, Chromium, Playwright 1.62.1 ו־axe-core 4.10.3. הנתונים שנוצרו לבדיקות פונקציונליות ולצילומים הם נתוני דמה בלבד. הגיבוי המקורי לא הותקן כאתר חי ולא נשלחו לידים, מיילים או מסמכים.

## תוצאות

כל הסוויטות הבאות עברו ב־PHP 7.4.33 וב־PHP 8.4.26:

- **443 בדיקות שרת לכל runtime**: אינטגרציה 60, אבטחה וכספים 116, התחברות 13, קליטה אוטומטית 28, תקופות כפילות 55, טווחי תאריכים 98 ו־iCount 73.
- **104 בדיקות דפדפן לכל runtime**: ממשק ונגישות 37, frontend עם תגובות מדומות 32, קאש ותאימות לקוד המקורי מ־0.1.1 — 6, ו־iCount frontend — 29.
- שתי בקשות תשלום מקבילות יצרו תשלום אחד ושינו את היתרה פעם אחת, בשני runtimes.
- PHPCS/WPCS: אפס שגיאות ואפס אזהרות. תחביר כל 12 קובצי PHP של התוסף נבדק בשתי הגרסאות; תחביר JS ובניית esbuild עברו.
- נבדקה תאימות כאשר מטמון מחזיר סקריפט ישן ללא התחשבות בפרמטרי גרסה: כתובות הנכסים עם טביעת התוכן עוקפות אותו, והסקריפט המקורי מ־0.1.1 מציג הגדרות מול ה־API החדש בלי קריסה. לקוח ישן אינו יכול לאפס תקופת כפילות חדשה בשמירה.
- טווחים כוללים גבולות יום מלאים, תשלומים אמיתיים ללא אינדקס חודש, סוף שנה ו־29 בפברואר; PDF ו־Excel מקבלים אותו סינון. חיובים נשארים לפי חודשי שירות שלמים. נבדקו כותרות מדדים עם התקופה והמוסד, יישור checkbox בנייד והסרת הייבוא והטקסט המבוקש.
- iCount נבדק באמצעות HTTP מדומה בלבד: הרשאות ו־nonce, סוד שרת, שיוך לקוח בשני שלבים, דרישה, חשבונית, מס־קבלה מלאה וקבלות חלקיות; שמירת מועד הדרישה בחשבונית המשך, מע״מ ועיגול, TLS/redirect, כשל רשת/שמירה לאחר הפקה, מנעול פעולה מקבילה, בירור עם אותו sanity, חסימת פעולה לא ודאית ותאריך אחר, חשבון/לקוח שהשתנו, ו־Cron ללא התחזות למנהל. נבדק גם שאישור חוזר אינו מכניס חיוב ישן לאוטומציה.
- בריצות axe לא נמצאו violations בכללי WCAG A/AA שנבדקו. זו בדיקה אוטומטית של המסכים, ואינה מחליפה בדיקה ידנית או אישור נגישות.
- בדיקת מיפוי מול הגיבוי המקורי: 3,860 רשומות ניתנות לשיוך מדויק, עם 4,008 שיוכי מוסד; 66 רשומות משויכות לכמה מוסדות. 760 רשומות בעלות ערך מוסד לא ממופה ועוד שתיים ללא מטא מוסד. זו בדיקת מיפוי בלבד, ללא שליחה או חיוב.

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
LIMU_WP_ROOT=/workspace/.limu-tools/wordpress /workspace/.limu-tools/php74 tests/duplicate-periods.php
LIMU_WP_ROOT=/workspace/.limu-tools/wordpress /workspace/.limu-tools/php74 tests/date-range.php
LIMU_WP_ROOT=/workspace/.limu-tools/wordpress /workspace/.limu-tools/php74 tests/icount.php
LIMU_PHP=/workspace/.limu-tools/php74 LIMU_WP_ROOT=/workspace/.limu-tools/wordpress python3 tests/concurrency.py
/workspace/.limu-tools/php74 /workspace/.limu-tools/member-login.php
LIMU_TEST_URL=http://127.0.0.1:8091 LIMU_TEST_LOGIN=/workspace/.limu-tools/test-login.json LIMU_MEMBER_LOGIN=/workspace/.limu-tools/member-login.json LIMU_AXE_PATH=/workspace/limu/node_modules/axe-core/axe.min.js LIMU_SCREENSHOT_DIR=/workspace/limu/artifacts node tests/browser.cjs
LIMU_TEST_URL=http://127.0.0.1:8091 LIMU_TEST_LOGIN=/workspace/.limu-tools/test-login.json node tests/frontend-review.cjs
LIMU_TEST_URL=http://127.0.0.1:8091 LIMU_TEST_LOGIN=/workspace/.limu-tools/test-login.json node tests/settings-cache.cjs
LIMU_TEST_URL=http://127.0.0.1:8091 LIMU_TEST_LOGIN=/workspace/.limu-tools/test-login.json LIMU_MEMBER_LOGIN=/workspace/.limu-tools/member-login.json node tests/icount-frontend.cjs
/workspace/.limu-tools/php74 /workspace/.limu-tools/phpcs/bin/phpcs --standard=phpcs.xml
```

בדיקות שמשנות את מסד הנתונים רצות ברצף. גם בדיקות הדפדפן רצות ברצף בין גרסאות PHP: ההתחברות יוצרת WordPress session tokens באותה רשומת משתמש, ולכן הרצות מקבילות באותו חשבון עלולות לדרוס התחברות של הרצה אחרת.

הבדיקה הפונקציונלית מוחקת את רשומות הדמה במסד `limu_test`. אין לשנות את ההגנה או להפנות אותה למסד הייצור. קובצי ההתחברות לבדיקות מוגנים בהרשאות 0600 ונמצאים מחוץ ל־Git; אין להעתיק או להדפיס את ערכיהם.

כלי PHPCS/WPCS ששימשו להרצה נקראו מקוד המקור הרשמי: PHPCS commit `7f47350339831578dfab3eb288c12d18c1dfccfe`, WPCS commit `87988814d50a0d560ed89bf338300f85cbfb23a9`.

## מה טרם אומת

- Elementor Pro, JetFormBuilder והשליחה בפועל באתר המקורי: קובץ התוספים חורג ממגבלת העברה של 32MB. קוד התבנית הרלוונטי נקרא; חיבור רשומות מקור ו־HTTP נבדק עם fixtures, ללא הפעלת השליחה המקורית. יש לבצע בדיקת טופס אמיתי ב־staging. אין דרישה לאישור ידני לכל פנייה.
- החיבור ל־iCount מומש ונבדק עם HTTP מדומה לפי התיעוד הרשמי. לא בוצעו התחברות לחשבון אמיתי, יצירת לקוח או הפקת מסמך אמיתי. sandbox ואלגוריתם העיגול המלא אינם מפורטים בתיעוד שקראנו. הפעלה אמיתית דורשת טוקן שרת ובדיקת חשבון בדיקות. כרטיס ואמצעי תשלום כלליים אינם מחוברים להפקת קבלה; מזומן, העברה והמחאה נתמכים. זיכויים וביטולים נעשים ב־iCount עצמו.
- Turnstile נבדק עם תגובות ספק מדומות, כולל כשל טוקן/action ושמירת TLS. לא בוצעה בדיקה מול חשבון Cloudflare פעיל.
- לא אומתו PageSpeed/Core Web Vitals באתר החי, תוספי הקאש, SMTP, כל גרסאות WordPress/PHP המינימליות, restore של גיבוי הייצור או restoration במשימת Codex חדשה.
- לא בוצעו התקנה/פרסום באתר החי או פרסום snapshot של סביבת Codex. גרסה 0.1.3 נועדה להתקנה מתוך ZIP העדכון במאגר; אין כאן שינוי ישיר באתר החי.

## אימות PHP 7.4.33

הריצה בוצעה בבינארי PHP 7.4.33 אמיתי עם mysqli, JSON, XML, ctype, mbstring, tokenizer ו־ZipArchive. החבילות המקומיות אומתו באמצעות חתימת Debian וה־SHA256 של האינדקסים ושל החבילות. זהו runtime לבדיקת תאימות בלבד, לא המלצה להתקנת חבילת PHP ישנה על הייצור. ראיות אימות נשמרו מחוץ ל־Git בסביבת הכלים.

סוויטות הבדיקות האחרונות הושלמו בהצלחה בשתי הסביבות. לוגי ריצות וכשלים זמניים אינם אישור לאימות שעבר; תוצאות הסוויטות הנוכחיות נשמרות ב־artifacts. קובצי native.txt ו־php74-lint.txt הישנים נשמרו כראיות לגרסאות קודמות; הריצה הנוכחית היא native-capture*.txt ו־php-lint*.txt.

ה־patch לתבנית הבת נבדק ב־dry-run מול העותק שהתקבל בגיבוי; הוא לא הותקן ולא נבדקה תאימות מלאה של התבנית ל־PHP 8. דוח הסקירה: `SECURITY_REVIEW.md`.
