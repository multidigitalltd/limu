# iCount API v3 — חוזה רשמי מאומת

נבדק ב־7 באוקטובר 2026 מול התיעוד הציבורי הרשמי. הגישה לתיעוד פתוחה כעת. הבדיקה קראה תיעוד בלבד: לא בוצעה התחברות לחשבון, לא נוצר לקוח ולא הופק מסמך כספי. מסמך זה מתאר את חוזה הספק; אינו מצהיר שכל היכולות כבר מומשו בתוסף.

## מקורות

- [מבוא ואימות](https://apiv3.icount.co.il/#/welcome), והקובץ הרשמי [welcome.md](https://apiv3.icount.co.il/new_api_docs/welcome.md).
- [מסמכים](https://apiv3.icount.co.il/#/module/doc), [יצירה](https://apiv3.icount.co.il/#/module/doc/create), [מידע](https://apiv3.icount.co.il/#/module/doc/info), [חיפוש](https://apiv3.icount.co.il/#/module/doc/search).
- [לקוחות](https://apiv3.icount.co.il/#/module/client), [אימות](https://apiv3.icount.co.il/#/module/auth), [חשבונות הבנק של החברה](https://apiv3.icount.co.il/#/module/company/bank_accounts).
- סכמות JSON המשמשות את האתר הרשמי: `https://apiv3.icount.co.il/api/v3.php/help/methods/{module}?format=json&no_auth=1&no_status=1`, עבור `doc`, `client`, `auth`, `company`, `payment_method` ו־`vat`.
- [תצוגת HTML הרשמית של doc/create, כולל דוגמת כפל מלאה](https://apiv3.icount.co.il/api/v3.php/help/methods/doc/create?format=html&no_auth=1&no_status=1). תצוגת ה־SPA אינה מציגה את תוכן קובצי הדוגמאות, אך תצוגה זו כן כוללת אותו.

## תעבורה ואימות

כל פעולות החשבון מקבלות `POST`, גוף JSON ו־`Content-Type: application/json`, ומחזירות JSON. הכתובת הרשמית הקבועה היא `https://api.icount.co.il/api/v3.php/`, ואחריה שם מודול ופעולה.

האימות המומלץ רשמית הוא `Authorization: Bearer <API_TOKEN>`. יוצרים את הטוקן ב[הגדרות API Tokens](https://app.icount.co.il/admin/settings_automation.php#tabs-api-tokens). אין לו תפוגה עד לביטולו, והוא יורש בדיוק את הרשאות המשתמש שהנפיק אותו. התיעוד אינו קובע ביטוי רגולרי או אורך קבוע לטוקן.

חלופה מתועדת: `auth/login` עם `{ "cid": "…", "user": "…", "pass": "…" }` מחזיר `sid`. מוסיפים `sid` לגוף בקשות ההמשך. משך התוקף הוא 20 דקות מהבקשה האחרונה; כל בקשה מאריכה אותו. `auth/logout` סוגר את ההתחברות. מותר גם לשלוח את שלושת פרטי ההתחברות בכל בקשה, אך זו אינה החלופה המומלצת לחיבור שרת קבוע.

בתגובות סכמות העזרה נצפו מעטפת `status: true` ו־`reason: "OK"`. התיעוד של פעולות החשבון מציג שדות תוצאה וחריגות, ואינו מגדיר כאן באופן מלא את מעטפת השגיאות לכל פעולה. הצלחה דורשת גם את שדות התוצאה המצופים, ולא הסתמכות על HTTP 200 בלבד.

## לקוחות

`client/info` מקבל לפחות אחד מהשדות הבאים, בסדר העדיפות הרשמי: `client_id`, `custom_client_id`, `vat_id`, `email`, `client_name`. החיבור למוסד צריך לאמת מזהה `client_id` חיובי במפורש; שם או כתובת דוא״ל בלבד אינם הוכחת שיוך.

התוצאה היא `client_info`, הכולל `client_id`, `custom_client_id`, `vat_id`, `client_name`, `company_name`, `email`, `phone` ופרטי כתובת `bus_country`, `bus_city`, `bus_street`, `bus_no` ושדות נוספים. מזהה `cid` של מערכת הלידים הישנה אינו מזהה לקוח iCount. גם `cid` באימות הוא מזהה החברה של חשבון iCount, ולא מזהה הלקוח המחויב.

`client/get_list` תומך בסינון לפי `client_name`, `vat_id`, `email`, `phone` ועוד, וכן `offset`, `limit`, `detail_level` ו־`list_type: "array"`. הוא מחזיר `clients_count` ו־`clients`.

`client/create` דורש רק `client_name`. `custom_client_id`, `vat_id`, `email`, `phone` ופרטי הכתובת הם אופציונליים. הוא מחזיר `client_id` ויכול להחזיר `custom_client_id`. ההערה הרשמית אומרת שהפעולה נכשלת אם הלקוח כבר קיים. אין צורך ליצור לקוח כשמנהל מקשר לקוח קיים.

## הפקת מסמכים

`doc/create` דורש `doctype`. סוגים רשמיים רלוונטיים: `deal`, `invoice`, `invrec` ו־`receipt`. הספק דורש לבדוק `doc/types` כדי לדעת אילו סוגים זמינים לחשבון המסוים; שדות התוצאה כוללים בין היתר `has_vat`, `has_items`, `has_payment` ו־`has_paydate`.

| שדה | משמעות מאומתת |
|---|---|
| `client_id` | מזהה לקוח. יש לשלוח את המזהה המאומת כדי למנוע יצירה או איתור שגוי של לקוח לפי חלופות אחרות. |
| `doc_date` | תאריך המסמך; ברירת המחדל היום. דוגמאות התיעוד לתאריכים משתמשות ב־`YYYY-MM-DD`. |
| `paydate` | מועד תשלום אחרון עבור `deal` ו־`invoice`. `duedate` שייך ל־`order` ול־`offer`. |
| `currency_code` | קוד ISO 4217; ברירת המחדל `ILS`. הסכומים המסוכמים מתוארים כשקלים גם במסמכים במטבע אחר. |
| `vat_percent` | שיעור המע״מ, כאחוז מספרי. בחיבור הזה: 18. |
| `items` | מערך שורות; חובה בסוגים הרלוונטיים חוץ מ־`receipt`. |
| `totalsum` | סך לפני מע״מ ולפני הנחה ועיגול. |
| `afterdiscount` | סך לפני מע״מ ולאחר הנחה ועיגול. |
| `totalwithvat` | סך כולל מע״מ. |
| `paid` | הסכום ששולם בכל אמצעי התשלום. |
| `based_on` | מערך `{ "doctype": "…", "docnum": 123 }` של מסמכי מקור. |
| `sanity_string` | מזהה ייחודי, עד 30 תווים; הספק מצהיר שמונע הפקת מסמך כפול עם אותו ערך. |
| `send_email`, `send_sms` | ברירת המחדל של שניהם `false`. |

שורת `iCountDocItem` דורשת `description`, `unitprice` — מחיר לפני מע״מ — ו־`quantity`. `unitprice_incvat` הוא חלופה אופציונלית שממנה הספק מחלץ את המחיר לפני מע״מ. בשורות חיוב CRM יש להשתמש במחיר לפני מע״מ ובנתוני החיוב המאושר.

תגובה מוצלחת של `doc/create` כוללת `client_id`, `custom_client_id`, `doctype`, `docnum`, `doc_url`, `doc_copy_url`; `invoice_reference_number` עשוי להופיע כאשר רלוונטי. סכום ותאריך המסמך אינם חלק מהשדות המוצהרים בתוצאת יצירה, ולכן יש לאמת אותם ב־`doc/info` לפני הצגת המסמך כמאומת.

## תשלומים

לפחות אמצעי תשלום אחד שאינו ריק נדרש עבור `receipt` ו־`invrec`. המבנים הבאים רשמיים:

| שדה בבקשת `doc/create` | שדות פנימיים נדרשים |
|---|---|
| `cash` | `{ "sum": amount }` |
| `banktransfer` | `{ "sum": amount, "date": "YYYY-MM-DD", "account": account_id }` |
| `cheques` | מערך `{ "sum": amount, "date": "YYYY-MM-DD", "bank": n, "branch": n, "account": n, "number": n }` |
| `payments` | אובייקט שמפתחותיו קודי אמצעי תשלום פעילים, וכל ערך דורש `sum`; `payment_date`, `confirmation_code` ושדות נוספים אופציונליים. |

`banktransfer.account` הוא מזהה חשבון הבנק של החברה ב־iCount, ולא מספר חשבון בנק חופשי. `company/bank_accounts` מחזיר את הרשימה עם `account_id`, `title`, `bank`, `branch` ו־`account`. אין להמציא חשבון ברירת מחדל.

קודי האמצעים הכלליים חייבים להגיע מ־`doc/payment_methods` או מ־`payment_method/get_list`. אין בתיעוד רשימה קבועה שמתירה למפות קוד CRM כמו `other` לקוד ספק לפי ניחוש. המבנה הכללי מאפשר `payment_date`, `confirmation_code` ו־`card_number` המתואר כארבע הספרות האחרונות כאשר רלוונטי.

המבנה הייעודי `cc` מתאר מספר כרטיס, CVV, תוקף ופרטי מחזיק. אין להסיק ממנו שאפשר להפיק קבלה על תשלום בכרטיס עם פרטי CRM הקיימים, ואין לאסוף פרטי כרטיס מלאים לצורך החיבור הזה.

## בירור תוצאה ומניעת כפל

`doc/info` דורש `doctype` ו־`docnum`, ומחזיר `doc_info`. המידע המוצהר כולל `client_id`, `dateissued`, `timeissued` — זמן ההפקה בפועל — `currency_code`, `vat_percent`, `totalsum`, `totalwithvat`, `paid`, `status`, `is_cancelled`, `based_on` ו־`based_on_this`. `get_items` ו־`get_payments` הם `true` כברירת מחדל.

`doc/search` דורש לפחות שדה סינון אחד. ניתן לסנן לפי לקוח, סוג, מספר, תאריכי מסמך, זמני הפקה בפועל ומצב, ולקבל `results_count` ו־`results_list`. `detail_level` נע בין 0 ל־10; 10 כולל שורות ופרטי תשלום. `max_results` מוגבל ל־0–1000, עם ברירת מחדל 100. `offset` ו־`limit` משמשים לעימוד.

חריגת הכפל המתועדת היא `doc_exists_based_on_sanity_string`. סכמת JSON וה־SPA אינם מציגים את תוכן קובץ הדוגמה, אך בתצוגת HTML הרשמית מופיעה דוגמה מלאה:

```json
{
  "status": false,
  "reason": "doc_exists_based_on_sanity_string",
  "client_id": 2,
  "custom_client_id": "",
  "doctype": "invoice",
  "docnum": "1234",
  "doc_url": "https://dev.icount.co.il/hash/p_print.php?code=**DOC_ORIG_MAILCODE**",
  "doc_copy_url": "https://dev.icount.co.il/hash/p_print.php?code=**DOC_COPY_MAILCODE**",
  "error_description": "מסמך עם מחרוזת שפיות שציינת כבר קיים",
  "error_details": []
}
```

זו תגובת כפל ולא הצלחה רגילה; `docnum` בדוגמה הוא מחרוזת מספרית. ניתן להשתמש במספר רק כאשר סיבת הכשל, הלקוח והסוג תואמים לכוונת ההפקה, ואז לאמת את המסמך באמצעות `doc/info`.

אין `sanity_string` בין מסנני `doc/search` או בשדות המוצהרים של `iCountDocInfo`. התיעוד הציבורי אינו מגדיר היקף ייחודיות בין סוגי מסמכים או משך שמירת המזהה. יש לשמור מפתח יציב, גוף בקשה קפוא ומצב הפקה מקומי לפני שליחה, ולחסום הפקה חלופית בזמן תוצאה לא ודאית. פעולת בירור מפורשת יכולה לשלוח שוב בדיוק את גוף הבקשה הקפוא עם אותו `sanity_string`: תגובת הכפל המוצהרת מספקת את מספר המסמך המקורי לבדיקת `doc/info`. אין לשנות סוג, סכום, לקוח, תאריך או מפתח במהלך הבירור, ואין לבצע ניסיונות אוטומטיים עם מפתח חדש. אין לשייך מסמך שרירותי רק משום שלקוח וסכום נראים דומים.

## גבולות שדורשים אימות בחשבון בדיקות

התיעוד מצהיר על `based_on`, `doc/get_doc_conversion_options` ו־`doc/prepare_base_docs_conversion`, אבל אינו מסביר כאן את כללי הקצאת הסכומים בהמרה חלקית. אין להסיק ממנו שחיוב מלא יחד עם `invrec` חלקית יחייבו במס רק את הסכום שהתקבל. מסלול בטוח למימוש שמרני הוא חשבונית על החיוב המאושר וקבלה על התשלום, או `invrec` מלאה כאשר אין חשבונית קודמת וכל הסכום התקבל; יש לאמת גם את המסלול הזה בחשבון בדיקות של הספק.

אלגוריתם העיגול של הספק אינו מוגדר. ה־CRM מעגל מע״מ לכל ליד בנפרד. לדוגמה, שלוש שורות של 0.03 ₪ נותנות ב־CRM מע״מ כולל 0.03 ₪, בעוד עיגול מע״מ על הסכום המצטבר 0.09 ₪ נותן 0.02 ₪. אין להניח ששורות נפרדות או שליחת סכומים מפורשים מבטיחות התאמה. נדרש למנוע שליחה כשקיימת אי־התאמה ידועה, לבדוק סכומים לאחר הפקה, ולסמן לבירור תוצאה שאינה תואמת בלי לשנות את החיוב המאושר או להפיק מסמך נוסף.

לא נמצאה בתיעוד שנקרא כתובת סביבת sandbox או הבטחה למצב dry-run של `doc/create`. בדיקות התוסף חייבות להשתמש בתגובות מדומות; בדיקה אמיתית דורשת חשבון בדיקות שיוגדר אצל הספק, ללא הפקת מסמכים בחשבון הפעיל בזמן הפיתוח.
