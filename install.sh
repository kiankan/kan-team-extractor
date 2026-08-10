#!/usr/bin/env bash
# ==============================================================================
#  install.sh — نصب‌کننده و ابزار مدیریت از راه دور برای بات تیم کن.
#  (installer + remote management tool for the Team Kan bot — supports Persian
#  and English, pick with --lang=fa / --lang=en or the interactive prompt)
#
#  دو حالت اجرا:
#    الف) از راه دور، مستقیم از گیت‌هاب (بدون نیاز به فایل محلی):
#         curl -sL https://raw.githubusercontent.com/kiankan/kan-team-extractor/main/install.sh \
#           | sudo bash -s -- install --domain=bot.example.com --token=BOT_TOKEN --admin=ADMIN_ID
#
#    ب) به‌صورت محلی، بعد از یک‌بار نصب (به عنوان /usr/local/bin/kanbot نصب می‌شه):
#         sudo kanbot                منوی مدیریت تعاملی رو باز می‌کنه
#         sudo kanbot menu           همون بالایی
#         sudo kanbot update         آخرین نسخه رو از گیت‌هاب می‌کشه و دوباره دیپلوی می‌کنه
#         sudo kanbot info           اطلاعات نصب رو نشون می‌ده (دامنه، آدرس پنل، دیتابیس و ...)
#         sudo kanbot status         وضعیت سرویس‌ها رو نشون می‌ده
#         sudo kanbot restart        همه‌ی سرویس‌های مرتبط رو ری‌استارت می‌کنه
#         sudo kanbot restore        یه بکاپ قبلی از دیتابیس/سورس رو روی همین نصب برمی‌گردونه
#         sudo kanbot uninstall      حذف کامل (تأیید می‌خواد)
#
#  چون نصب از طریق `curl | sudo bash` ورودی استاندارد واقعی برای خوندن جواب‌ها
#  نداره، دستورات `install` و `uninstall` وقتی ترمینال تعاملی وصل نباشه، به‌جای
#  پرسیدن سوال، فلگ قبول می‌کنن. به تابع usage() پایین‌تر نگاه کن.
#
#  زبان: هر دستوری می‌تونه --lang=fa یا --lang=en بگیره (مثلاً `install --lang=en`).
#  اگه فلگی داده نشه و ترمینال تعاملی وصل باشه، موقع نصب یه بار زبان پرسیده
#  می‌شه و برای دفعات بعد (sudo kanbot) هم ذخیره می‌مونه.
# ==============================================================================
set -uo pipefail

export LANG=en_US.UTF-8
export LC_ALL=en_US.UTF-8

# ------------------------------------------------------------------ colors ---
C_RESET='\033[0m'; C_GREEN='\033[1;32m'; C_RED='\033[1;31m'; C_YELLOW='\033[1;33m'
C_BLUE='\033[1;34m'; C_CYAN='\033[1;36m'; C_BOLD='\033[1m'
ok()   { echo -e "${C_GREEN}✔${C_RESET} $1"; }
err()  { echo -e "${C_RED}✘ $1${C_RESET}"; }
info() { echo -e "${C_CYAN}➜${C_RESET} $1"; }
warn() { echo -e "${C_YELLOW}⚠${C_RESET} $1"; }
title(){ echo -e "\n${C_BOLD}${C_BLUE}== $1 ==${C_RESET}"; }

# --------------------------------------------------------------- language ---
# APP_LANG: "fa" (پیش‌فرض) یا "en". با فلگ --lang=fa/--lang=en (هر جای خط فرمان
# بعد از اسم اسکریپت) یا پرسش تعاملی موقع نصب انتخاب می‌شه، و توی $CONF_FILE
# برای اجراهای بعدی (sudo kanbot) ذخیره می‌مونه.
APP_LANG="fa"
APP_LANG_EXPLICIT=""

declare -A MSG_FA
declare -A MSG_EN

# یه پیام رو بر اساس $APP_LANG برمی‌گردونه. آرگومان‌های اضافه جای %s های قالب
# می‌شینن (مثل printf). اگه کلید توی هیچ‌کدوم از دیکشنری‌ها نبود، خودِ کلید
# چاپ می‌شه (برای دیباگ ساده‌تر به‌جای شکستن اسکریپت).
t() {
    local key="$1"; shift
    local tmpl=""
    if [[ "${APP_LANG:-fa}" == "en" ]]; then
        tmpl="${MSG_EN[$key]-}"
        [[ -z "$tmpl" ]] && tmpl="${MSG_FA[$key]-}"
    else
        tmpl="${MSG_FA[$key]-}"
    fi
    if [[ -z "$tmpl" ]]; then
        printf '%s' "$key"
        return
    fi
    if [[ $# -gt 0 ]]; then
        printf -- "$tmpl" "$@"
    else
        printf '%s' "$tmpl"
    fi
}

MSG_FA=(
    [require_root_err]="این اسکریپت باید با دسترسی root اجرا بشه. مثال: sudo bash install.sh"
    [require_apt_err]="این نصب‌کننده فقط از توزیع‌های مبتنی بر Debian/Ubuntu (apt) پشتیبانی می‌کنه."
    [pause_prompt]="برای ادامه Enter را بزنید..."
    [tty_no_terminal]="ترمینال تعاملی برای دریافت ورودی در دسترس نیست: %s"
    [tty_use_flags]="به‌جاش با فلگ‌های مناسب دوباره اجرا کن (به install.sh --help نگاه کن)."

    [fetch_downloading]="در حال دانلود سورس از گیت‌هاب (%s، شاخه: %s)"
    [fetch_git_ok]="سورس با git کلون شد."
    [fetch_git_fail]="git clone در دسترس نیست یا شکست خورد؛ می‌ریم سراغ دانلود tarball."
    [fetch_tarball_fail]="دانلود سورس از %s شکست خورد (جزئیات: /tmp/teamkan_dl.log)"
    [fetch_extract_fail]="استخراج آرشیو سورس شکست خورد."
    [fetch_tarball_ok]="سورس با tarball دانلود شد."

    [restore_file_missing]="فایل %s پیدا نشد یا قابل خواندن نیست: %s"
    [restore_file_empty]="فایل %s خالیه: %s"
    [label_db_backup]="بکاپ دیتابیس"
    [label_source_backup]="بکاپ سورس"

    [collect_title]="در حال جمع‌آوری اطلاعات نصب"
    [prompt_domain]="دامنه‌ای که از قبل به این سرور اشاره می‌کنه (مثلاً bot.example.com): "
    [prompt_domain_retry]="دامنه نمی‌تونه خالی باشه. دوباره وارد کن: "
    [prompt_token]="توکن ربات (از @BotFather): "
    [prompt_token_retry]="توکن نمی‌تونه خالی باشه. دوباره وارد کن: "
    [prompt_admin]="آیدی عددی مدیر اصلی (از @userinfobot): "
    [prompt_admin_retry]="فقط باید عدد باشه. دوباره وارد کن: "
    [prompt_ssl_email]="ایمیلت برای گواهی SSL (Let's Encrypt) [اختیاری، برای رد شدن Enter بزن]: "
    [prompt_restore_db]="مسیر یک بکاپ دیتابیس قبلی (.sql) برای بازیابی [اختیاری، برای رد شدن Enter بزن]: "
    [prompt_restore_source]="مسیر یک بکاپ سورس قبلی (.zip) برای بازیابی [اختیاری، برای رد شدن Enter بزن]: "
    [summary_title]="خلاصه:"
    [lbl_domain]="دامنه:"
    [lbl_webroot]="مسیر فایل‌ها:"
    [lbl_dbname]="نام دیتابیس:"
    [lbl_dbuser]="یوزر دیتابیس:"
    [lbl_restore_db]="بازیابی دیتابیس:"
    [lbl_restore_source]="بازیابی سورس:"
    [confirm_proceed]="همه‌چیز درسته؟ ادامه بدیم؟ [Y/n]: "
    [install_cancelled]="نصب لغو شد."

    [unknown_flag_ignored]="گزینه‌ی ناشناخته نادیده گرفته شد: %s"
    [missing_required_flags]="گزینه‌های اجباری وارد نشدن: %s"
    [admin_must_be_number]="--admin فقط باید عدد باشه."
    [installing_with]="در حال نصب با: دامنه=%s ادمین=%s ایمیل=%s"
    [none_placeholder]="<هیچ‌کدام>"
    [will_restore_db]="بکاپ دیتابیس بازیابی می‌شه: %s"
    [will_restore_source]="بکاپ سورس بازیابی می‌شه: %s"

    [installing_packages_title]="در حال نصب پیش‌نیازها (nginx، PHP-FPM، MariaDB و ...)"
    [packages_fail]="نصب پکیج‌ها شکست خورد. جزئیات: /tmp/teamkan_apt.log"
    [packages_ok]="پیش‌نیازها نصب شدند."

    [phpfpm_not_found]="سرویس php-fpm پیدا نشد."
    [phpfpm_sock_not_found]="سوکت php-fpm پیدا نشد."

    [db_title]="در حال ساخت دیتابیس"
    [db_fail]="ساخت دیتابیس شکست خورد. آیا MariaDB درست نصب شده؟"
    [db_ok]="دیتابیس '%s' و یوزر '%s' ساخته شدند."

    [deploy_title]="در حال کپی فایل‌های پروژه به %s"
    [deploy_ok]="فایل‌ها کپی شدند و config.php ساخته شد."

    [restore_source_title]="در حال بازیابی بکاپ سورس قبلی"
    [unzip_missing]="unzip در دسترس نیست؛ نمی‌شه بکاپ سورس رو بازیابی کرد."
    [restore_source_fail]="استخراج بکاپ سورس شکست خورد. جزئیات: /tmp/teamkan_restore_source.log"
    [restore_source_ok]="بکاپ سورس از این فایل بازیابی شد: %s"

    [tables_title]="در حال ساخت جدول‌های دیتابیس"
    [tables_fail]="ساخت جدول‌ها شکست خورد. جزئیات: /tmp/teamkan_tables.log"
    [tables_ok]="جدول‌های دیتابیس ساخته شدند."

    [restore_db_title]="در حال بازیابی بکاپ دیتابیس قبلی"
    [restore_db_partial]="بکاپ دیتابیس بازیابی شد، ولی %s ردیف به‌خاطر تفاوت اسکیما رد شدن. جزئیات: %s"
    [restore_db_ok]="بکاپ دیتابیس از این فایل بازیابی شد: %s"

    [nginx_title]="در حال تنظیم Nginx"
    [nginx_invalid]="کانفیگ Nginx نامعتبره. جزئیات: /tmp/teamkan_nginx.log"
    [nginx_ok]="Nginx تنظیم شد (HTTP)."

    [ssl_title]="در حال دریافت گواهی SSL رایگان (Let's Encrypt)"
    [ssl_domain_warn]="برای موفقیت این مرحله، %s باید از قبل به آی‌پی همین سرور اشاره کنه."
    [ssl_ok]="گواهی SSL با موفقیت دریافت و فعال شد."
    [ssl_fail1]="دریافت SSL شکست خورد (جزئیات: /tmp/teamkan_certbot.log). فعلاً روی HTTP ادامه می‌دیم؛"
    [ssl_fail2]="بعد از اینکه DNS دامنه درست تنظیم شد، 'kanbot' رو اجرا کن و گزینه‌ی تمدید SSL رو بزن، یا 'sudo kanbot menu'."
    [ssl_fail3]="توجه: تلگرام فقط HTTPS رو برای وب‌هوک قبول می‌کنه، پس بات در دسترس نخواهد بود تا وقتی SSL درست بشه."

    [webhook_title]="در حال تنظیم وب‌هوک تلگرام"
    [webhook_ok]="وب‌هوک با موفقیت روی %s ست شد"
    [webhook_fail]="تنظیم وب‌هوک شکست خورد. پاسخ تلگرام: %s"

    [cron_title]="در حال تنظیم خودکار کرون‌جاب بکاپ"
    [cron_fail]="تنظیم خودکار کرون‌جاب شکست خورد؛ باید دستی crontab رو تنظیم کنی (crontab -e)."
    [cron_ok]="کرون‌جاب خودکار تنظیم شد (هر ۱ دقیقه سرمی‌زنه؛ فاصله‌ی واقعی ارسال بکاپ رو از تب «⏱ کرون» توی پنل وب یا داخل خود ربات تغییر بده)."

    [symlink_fail]="نتونستم یه نسخه‌ی دائمی از اسکریپت مدیریت رو ذخیره کنم؛ دستور 'kanbot' در دسترس نخواهد بود."
    [symlink_ok]="دستور مدیریت نصب شد: هر وقت خواستی با 'sudo kanbot' منو رو باز کن."

    [install_done_title]="نصب کامل شد 🎉"
    [lbl_panel_url]="آدرس پنل وب:"
    [lbl_default_pass]="رمز پیش‌فرض پنل:"
    [default_pass_warn]="(همین الان از تب 'Security' عوضش کن)"
    [lbl_manage_next]="برای مدیریت بعدی:"
    [manage_next_text]="دستور %s رو بزن تا منوی مدیریت باز بشه،"
    [manage_next_text2]="یا مستقیم %s رو بزن."
    [lbl_conf_saved]="اطلاعات نصب ذخیره شد در:"
    [lbl_restored_from]="بازیابی‌شده از بکاپ:"
    [no_db_placeholder]="<بدون دیتابیس>"
    [no_source_placeholder]="<بدون سورس>"

    [conf_not_found]="فایل کانفیگ نصب پیدا نشد (%s). اول باید نصب‌کننده رو اجرا کنی."

    [status_title]="وضعیت سرویس‌ها"
    [status_active]="%s: فعال"
    [status_inactive]="%s: غیرفعال"

    [restart_bot_title]="در حال ری‌استارت بات (PHP-FPM + Nginx)"
    [restarted]="%s ری‌استارت شد."
    [restart_all_title]="در حال ری‌استارت همه‌ی سرویس‌ها"

    [info_title]="اطلاعات نصب"

    [backup_title]="بکاپ دستی دیتابیس"
    [backup_saved]="بکاپ ذخیره شد در: %s"
    [backup_fail]="بکاپ شکست خورد. جزئیات: /tmp/teamkan_backup.log"

    [restore_nothing]="چیزی برای بازیابی نیست (نه --db دادی نه --source)."
    [restore_existing_title]="در حال بازیابی بکاپ روی نصب موجود"
    [restore_existing_warn]="این کار فایل‌های فعلی رو بازنویسی می‌کنه (بازیابی سورس) و/یا ردیف‌های دیتابیس فعلی رو آپدیت/اضافه می‌کنه (بازیابی دیتابیس)."
    [restore_done]="بازیابی کامل شد."

    [rewebhook_title]="در حال ریست کردن وب‌هوک"
    [rewebhook_ok]="وب‌هوک با موفقیت ریست شد."
    [telegram_response]="پاسخ تلگرام: %s"

    [reset_pass_title]="در حال تغییر رمز پنل وب"
    [prompt_new_pass]="رمز جدید پنل رو وارد کن: "
    [pass_empty]="رمز نمی‌تونه خالی باشه."
    [pass_changed]="رمز پنل با موفقیت تغییر کرد."
    [pass_update_fail]="آپدیت رمز شکست خورد."

    [ssl_renew_title]="در حال تمدید/دریافت گواهی SSL"
    [ssl_renew_ok]="SSL تمدید/فعال شد."
    [ssl_renew_fail]="عملیات SSL شکست خورد. لاگ certbot رو چک کن (certbot certificates)."

    [update_title]="در حال آپدیت فایل‌های بات"
    [update_no_botphp]="bot.php توی سورس دانلودشده پیدا نشد؛ آپدیت لغو شد."
    [update_backup_ok]="فایل‌های فعلی بکاپ گرفته شدن در: %s"
    [update_done]="بات با موفقیت به آخرین نسخه‌ی شاخه‌ی '%s' آپدیت شد. config.php دست‌نخورده باقی موند."

    [uninstall_title]="حذف کامل"
    [uninstall_warn]="این کار مسیر فایل‌ها (%s)، دیتابیس (%s) و کانفیگ Nginx مربوط به %s رو برای همیشه پاک می‌کنه."
    [prompt_confirm_delete]="برای تأیید کلمه‌ی 'DELETE' رو تایپ کن: "
    [cancelled]="لغو شد."
    [uninstall_done]="حذف کامل انجام شد."

    [menu_header]="پنل مدیریت بات تیم کن"
    [menu_1]=" 1) وضعیت سرویس‌ها"
    [menu_2]=" 2) ری‌استارت بات (PHP-FPM + Nginx)"
    [menu_3]=" 3) ری‌استارت همه‌ی سرویس‌ها (شامل MariaDB)"
    [menu_4]=" 4) آپدیت بات (کشیدن آخرین نسخه از گیت‌هاب)"
    [menu_5]=" 5) نمایش اطلاعات نصب"
    [menu_6]=" 6) بکاپ دستی دیتابیس"
    [menu_7]=" 7) بازیابی یک بکاپ قبلی (دیتابیس و/یا سورس)"
    [menu_8]=" 8) ریست وب‌هوک تلگرام"
    [menu_9]=" 9) تغییر رمز پنل"
    [menu_10]="10) تمدید/دریافت گواهی SSL"
    [menu_11]="11) حذف کامل"
    [menu_12]="12) تغییر زبان"
    [menu_0]=" 0) خروج"
    [prompt_menu_choice]="یک گزینه انتخاب کن: "
    [invalid_choice]="گزینه‌ی نامعتبر."
    [change_lang_title]="در حال تغییر زبان"
    [lang_changed]="زبان با موفقیت تغییر کرد."

    [unknown_cmd]="دستور ناشناخته: %s"
)

MSG_EN=(
    [require_root_err]="This script must be run as root. Example: sudo bash install.sh"
    [require_apt_err]="This installer only supports Debian/Ubuntu-based distros (apt)."
    [pause_prompt]="Press Enter to continue..."
    [tty_no_terminal]="No interactive terminal available to read input: %s"
    [tty_use_flags]="Re-run with the appropriate flags instead (see install.sh --help)."

    [fetch_downloading]="Downloading source from GitHub (%s, branch: %s)"
    [fetch_git_ok]="Source cloned with git."
    [fetch_git_fail]="git clone is unavailable or failed; falling back to a tarball download."
    [fetch_tarball_fail]="Downloading source from %s failed (details: /tmp/teamkan_dl.log)"
    [fetch_extract_fail]="Extracting the source archive failed."
    [fetch_tarball_ok]="Source downloaded via tarball."

    [restore_file_missing]="%s file not found or unreadable: %s"
    [restore_file_empty]="%s file is empty: %s"
    [label_db_backup]="database backup"
    [label_source_backup]="source backup"

    [collect_title]="Collecting install info"
    [prompt_domain]="Domain that already points to this server (e.g. bot.example.com): "
    [prompt_domain_retry]="Domain can't be empty. Enter it again: "
    [prompt_token]="Bot token (from @BotFather): "
    [prompt_token_retry]="Token can't be empty. Enter it again: "
    [prompt_admin]="Main admin's numeric ID (from @userinfobot): "
    [prompt_admin_retry]="Must be a number. Enter it again: "
    [prompt_ssl_email]="Your email for the SSL certificate (Let's Encrypt) [optional, press Enter to skip]: "
    [prompt_restore_db]="Path to a previous database backup (.sql) to restore [optional, press Enter to skip]: "
    [prompt_restore_source]="Path to a previous source backup (.zip) to restore [optional, press Enter to skip]: "
    [summary_title]="Summary:"
    [lbl_domain]="Domain:"
    [lbl_webroot]="File path:"
    [lbl_dbname]="Database name:"
    [lbl_dbuser]="Database user:"
    [lbl_restore_db]="Database restore:"
    [lbl_restore_source]="Source restore:"
    [confirm_proceed]="Does everything look right? Continue? [Y/n]: "
    [install_cancelled]="Installation cancelled."

    [unknown_flag_ignored]="Unknown option ignored: %s"
    [missing_required_flags]="Missing required options: %s"
    [admin_must_be_number]="--admin must be a number."
    [installing_with]="Installing with: domain=%s admin=%s email=%s"
    [none_placeholder]="<none>"
    [will_restore_db]="Database backup will be restored: %s"
    [will_restore_source]="Source backup will be restored: %s"

    [installing_packages_title]="Installing prerequisites (nginx, PHP-FPM, MariaDB, ...)"
    [packages_fail]="Installing packages failed. Details: /tmp/teamkan_apt.log"
    [packages_ok]="Prerequisites installed."

    [phpfpm_not_found]="php-fpm service not found."
    [phpfpm_sock_not_found]="php-fpm socket not found."

    [db_title]="Creating database"
    [db_fail]="Creating the database failed. Is MariaDB installed correctly?"
    [db_ok]="Database '%s' and user '%s' created."

    [deploy_title]="Copying project files to %s"
    [deploy_ok]="Files copied and config.php created."

    [restore_source_title]="Restoring previous source backup"
    [unzip_missing]="unzip isn't available; can't restore the source backup."
    [restore_source_fail]="Extracting the source backup failed. Details: /tmp/teamkan_restore_source.log"
    [restore_source_ok]="Source backup restored from: %s"

    [tables_title]="Creating database tables"
    [tables_fail]="Creating tables failed. Details: /tmp/teamkan_tables.log"
    [tables_ok]="Database tables created."

    [restore_db_title]="Restoring previous database backup"
    [restore_db_partial]="Database backup restored, but %s row(s) were skipped due to schema differences. Details: %s"
    [restore_db_ok]="Database backup restored from: %s"

    [nginx_title]="Configuring Nginx"
    [nginx_invalid]="Nginx config is invalid. Details: /tmp/teamkan_nginx.log"
    [nginx_ok]="Nginx configured (HTTP)."

    [ssl_title]="Obtaining a free SSL certificate (Let's Encrypt)"
    [ssl_domain_warn]="For this step to succeed, %s must already point to this server's IP."
    [ssl_ok]="SSL certificate obtained and enabled successfully."
    [ssl_fail1]="Obtaining SSL failed (details: /tmp/teamkan_certbot.log). Continuing on HTTP for now;"
    [ssl_fail2]="once the domain's DNS is set up correctly, run 'kanbot' and pick the SSL renew option, or use 'sudo kanbot menu'."
    [ssl_fail3]="Note: Telegram only accepts HTTPS for webhooks, so the bot won't be reachable until SSL is fixed."

    [webhook_title]="Setting up the Telegram webhook"
    [webhook_ok]="Webhook set successfully at %s"
    [webhook_fail]="Setting the webhook failed. Telegram's response: %s"

    [cron_title]="Automatically setting up the backup cron job"
    [cron_fail]="Automatic cron job setup failed; you'll need to configure crontab manually (crontab -e)."
    [cron_ok]="Automatic cron job set up (checks in every 1 minute; change the actual backup-send interval from the '⏱ Cron' tab in the web panel or inside the bot itself)."

    [symlink_fail]="Couldn't save a persistent copy of the management script; the 'kanbot' command won't be available."
    [symlink_ok]="Management command installed: run 'sudo kanbot' any time to open the menu."

    [install_done_title]="Installation complete 🎉"
    [lbl_panel_url]="Web panel URL:"
    [lbl_default_pass]="Default panel password:"
    [default_pass_warn]="(change it right now from the 'Security' tab)"
    [lbl_manage_next]="For future management:"
    [manage_next_text]="run %s to open the management menu,"
    [manage_next_text2]="or run %s directly."
    [lbl_conf_saved]="Install info saved to:"
    [lbl_restored_from]="Restored from backup:"
    [no_db_placeholder]="<no database>"
    [no_source_placeholder]="<no source>"

    [conf_not_found]="Install config file not found (%s). Run the installer first."

    [status_title]="Service status"
    [status_active]="%s: active"
    [status_inactive]="%s: inactive"

    [restart_bot_title]="Restarting the bot (PHP-FPM + Nginx)"
    [restarted]="%s restarted."
    [restart_all_title]="Restarting all services"

    [info_title]="Install info"

    [backup_title]="Manual database backup"
    [backup_saved]="Backup saved to: %s"
    [backup_fail]="Backup failed. Details: /tmp/teamkan_backup.log"

    [restore_nothing]="Nothing to restore (you gave neither --db nor --source)."
    [restore_existing_title]="Restoring backup onto the existing install"
    [restore_existing_warn]="This will overwrite current files (source restore) and/or update/add current database rows (database restore)."
    [restore_done]="Restore complete."

    [rewebhook_title]="Resetting the webhook"
    [rewebhook_ok]="Webhook reset successfully."
    [telegram_response]="Telegram's response: %s"

    [reset_pass_title]="Changing the web panel password"
    [prompt_new_pass]="Enter the new panel password: "
    [pass_empty]="Password can't be empty."
    [pass_changed]="Panel password changed successfully."
    [pass_update_fail]="Updating the password failed."

    [ssl_renew_title]="Renewing/obtaining SSL certificate"
    [ssl_renew_ok]="SSL renewed/enabled."
    [ssl_renew_fail]="SSL operation failed. Check the certbot log (certbot certificates)."

    [update_title]="Updating bot files"
    [update_no_botphp]="bot.php wasn't found in the downloaded source; update cancelled."
    [update_backup_ok]="Current files backed up to: %s"
    [update_done]="Bot successfully updated to the latest version on branch '%s'. config.php was left untouched."

    [uninstall_title]="Full removal"
    [uninstall_warn]="This will permanently delete the file path (%s), the database (%s), and the Nginx config for %s."
    [prompt_confirm_delete]="Type 'DELETE' to confirm: "
    [cancelled]="Cancelled."
    [uninstall_done]="Full removal complete."

    [menu_header]="Team Kan Bot management panel"
    [menu_1]=" 1) Service status"
    [menu_2]=" 2) Restart bot (PHP-FPM + Nginx)"
    [menu_3]=" 3) Restart all services (including MariaDB)"
    [menu_4]=" 4) Update bot (pull latest from GitHub)"
    [menu_5]=" 5) Show install info"
    [menu_6]=" 6) Manual database backup"
    [menu_7]=" 7) Restore a previous backup (database and/or source)"
    [menu_8]=" 8) Reset Telegram webhook"
    [menu_9]=" 9) Change panel password"
    [menu_10]="10) Renew/obtain SSL certificate"
    [menu_11]="11) Full removal"
    [menu_12]="12) Change language"
    [menu_0]=" 0) Exit"
    [prompt_menu_choice]="Choose an option: "
    [invalid_choice]="Invalid option."
    [change_lang_title]="Changing language"
    [lang_changed]="Language changed successfully."

    [unknown_cmd]="Unknown command: %s"
)

# ------------------------------------------------------------------- repo ---
REPO_URL="https://github.com/kiankan/kan-team-extractor"
REPO_BRANCH="main"
INSTALL_SCRIPT_URL="https://raw.githubusercontent.com/kiankan/kan-team-extractor/${REPO_BRANCH}/install.sh"

CONF_DIR="/etc/teamkan-bot"
CONF_FILE="$CONF_DIR/install.conf"
PERSIST_SCRIPT_PATH="$CONF_DIR/install.sh"
BACKUP_DIR="/root/teamkan-backups"
# SELF_PATH ممکنه وقتی از طریق curl|bash اجرا می‌شه به یه فایل واقعی اشاره نکنه —
# هرگز روی این متغیر برای چیز مهمی حساب نکن، فقط به‌عنوان بهترین تلاش ممکن.
SELF_PATH="$(readlink -f "${BASH_SOURCE[0]:-}" 2>/dev/null || true)"

require_root() {
    if [[ $EUID -ne 0 ]]; then
        err "$(t require_root_err)"
        exit 1
    fi
}

require_apt() {
    if ! command -v apt-get >/dev/null 2>&1; then
        err "$(t require_apt_err)"
        exit 1
    fi
}

pause() {
    local p; p="$(t pause_prompt)"
    read -rp "$p" _ < /dev/tty 2>/dev/null || read -rp "$p" _
}

rand_pass() { openssl rand -hex 16; }

# رشته‌ی داده‌شده رو طوری escape می‌کنه که بشه امن داخل یه رشته‌ی تک‌کوتیشن (')
# سورس PHP جاش داد (مثلاً موقع نوشتن config.php) — بدون این، اگه توکن ربات یا
# رمز عبور یه کوتیشن تک توش داشته باشه، فایل PHP تولیدشده سینتکسش می‌شکنه.
php_squote_escape() {
    local s="$1"
    s="${s//\\/\\\\}"
    s="${s//\'/\\\'}"
    printf '%s' "$s"
}

# یه خط ورودی تعاملی می‌خونه حتی وقتی خود اسکریپت از طریق `curl | sudo bash` پایپ
# شده باشه (که در اون حالت stdin سورس خود اسکریپته، نه صفحه‌کلید). /dev/tty
# همچنان همون ترمینال واقعیه تا وقتی یه ترمینال وصل باشه، پس از همونجا می‌خونیم.
# استفاده: tty_read "پیام: " VARNAME [silent]
tty_read() {
    local __prompt="$1" __var="$2" __silent="${3:-}"
    if [[ ! -r /dev/tty ]]; then
        err "$(t tty_no_terminal "$__prompt")"
        err "$(t tty_use_flags)"
        exit 1
    fi
    if [[ "$__silent" == "silent" ]]; then
        read -rsp "$__prompt" "$__var" < /dev/tty; echo
    else
        read -rp "$__prompt" "$__var" < /dev/tty
    fi
    # فاصله‌های اضافه‌ی ابتدا/انتها و \r اضافه (که موقع کپی/پیست معمولاً پیش میاد) رو
    # حذف می‌کنیم تا یه کاراکتر نامرئی به‌طور خاموش یه مقایسه (مثل چک کردن دقیق کلمه‌ی DELETE) رو خراب نکنه
    local __val="${!__var}"
    __val="${__val%$'\r'}"
    __val="${__val#"${__val%%[![:space:]]*}"}"
    __val="${__val%"${__val##*[![:space:]]}"}"
    printf -v "$__var" '%s' "$__val"
}

# یه بار ابتدای اجرای install (وقتی هیچ فلگ --lang داده نشده و ترمینال تعاملی
# وصله) زبان رو تعاملی می‌پرسه. پیام به هر دو زبان نشون داده می‌شه چون هنوز
# نمی‌دونیم کاربر کدوم زبان رو می‌خواد.
select_language_interactive() {
    [[ -r /dev/tty ]] || return 0
    echo
    echo -e "${C_BOLD}زبان نصب‌کننده رو انتخاب کن / Choose installer language:${C_RESET}"
    echo "  1) فارسی (پیش‌فرض / default)"
    echo "  2) English"
    local choice
    read -rp "شماره / number [1]: " choice < /dev/tty
    case "$choice" in
        2) APP_LANG="en" ;;
        *) APP_LANG="fa" ;;
    esac
}

# آرگومان‌های ورودی رو برای فلگ‌های --lang=fa/--lang=en اسکن می‌کنه، APP_LANG رو
# ست می‌کنه، و بقیه‌ی آرگومان‌ها (بدون --lang) رو توی REMAINING_ARGS می‌ذاره تا
# پردازش‌کننده‌های بعدی (parse_install_args و ...) درباره‌ی --lang هشدار
# «گزینه‌ی ناشناخته» ندن.
parse_and_strip_lang() {
    REMAINING_ARGS=()
    local a
    for a in "$@"; do
        case "$a" in
            --lang=fa) APP_LANG="fa"; APP_LANG_EXPLICIT="1" ;;
            --lang=en) APP_LANG="en"; APP_LANG_EXPLICIT="1" ;;
            --lang=*) ;;
            *) REMAINING_ARGS+=("$a") ;;
        esac
    done
}

usage() {
    if [[ "$APP_LANG" == "en" ]]; then
        cat <<USAGE
Usage:
  sudo bash install.sh install                          Interactive install (asks questions)
  sudo bash install.sh install [--domain=D] [--token=T] [--admin=A] [--email=E]
                                [--restore-db=/path/db_backup.sql] [--restore-source=/path/source_backup.zip]
                                                          Silent/unattended install (no questions)
  sudo bash install.sh update
  sudo bash install.sh info
  sudo bash install.sh status
  sudo bash install.sh restart
  sudo bash install.sh restore [--db=/path/db_backup.sql] [--source=/path/source_backup.zip]
  sudo bash install.sh uninstall [--yes]
  sudo bash install.sh menu

Language: add --lang=en or --lang=fa right after any command above to force
a language for that run (e.g. 'install --lang=en'). If omitted and a terminal
is attached, 'install' asks once and remembers the choice for 'sudo kanbot'.

Restoring a previous backup during install:
  --restore-db=PATH      Path (on this server) to a db_backup_*.sql file created
                          by the bot's "database backup" feature (from Telegram
                          or the web panel). Imported right after fresh tables
                          are created, so old data stays compatible with the new
                          install's schema.
  --restore-source=PATH  Path (on this server) to a source_backup_*.zip file
                          created by the bot's "source backup" feature. Extracted
                          onto the freshly deployed files (before database tables
                          are created); config.php is always left untouched.
  Both are optional and can be used together or separately. The same flags
  without the "restore-" prefix (--db=, --source=) also work on the standalone
  'restore' command, to restore a backup onto an existing install.

Remote one-liner (no local file needed) — interactive, asks questions one by
one exactly like running it locally:
  curl -sL $INSTALL_SCRIPT_URL | sudo bash -s -- install

Remote one-liner, fully unattended (all answers given up front as flags):
  curl -sL $INSTALL_SCRIPT_URL | sudo bash -s -- install \\
      --domain=bot.example.com --token=BOT_TOKEN --admin=ADMIN_ID [--email=you@example.com] \\
      [--restore-db=/root/db_backup.sql] [--restore-source=/root/source_backup.zip]

  curl -sL $INSTALL_SCRIPT_URL | sudo bash -s -- update
  curl -sL $INSTALL_SCRIPT_URL | sudo bash -s -- info
  curl -sL $INSTALL_SCRIPT_URL | sudo bash -s -- restart
  curl -sL $INSTALL_SCRIPT_URL | sudo bash -s -- restore --db=/root/db_backup.sql --source=/root/source_backup.zip
  curl -sL $INSTALL_SCRIPT_URL | sudo bash -s -- uninstall --yes
USAGE
    else
        cat <<USAGE
راهنما:
  sudo bash install.sh install                          نصب تعاملی (سوال می‌پرسه)
  sudo bash install.sh install [--domain=D] [--token=T] [--admin=A] [--email=E]
                                [--restore-db=/path/db_backup.sql] [--restore-source=/path/source_backup.zip]
                                                          نصب بی‌صدا/خودکار (بدون هیچ سوالی)
  sudo bash install.sh update
  sudo bash install.sh info
  sudo bash install.sh status
  sudo bash install.sh restart
  sudo bash install.sh restore [--db=/path/db_backup.sql] [--source=/path/source_backup.zip]
  sudo bash install.sh uninstall [--yes]
  sudo bash install.sh menu

زبان: بعد از هر کدوم از دستورات بالا می‌تونی --lang=en یا --lang=fa بذاری تا
همون اجرا با اون زبان انجام بشه (مثلاً 'install --lang=en'). اگه چیزی ندی و
ترمینال تعاملی وصل باشه، دستور 'install' یه بار می‌پرسه و برای 'sudo kanbot'
هم به خاطر می‌سپاره.

بازیابی یک بکاپ قبلی هنگام نصب:
  --restore-db=PATH      مسیر (روی همین سرور) یک فایل db_backup_*.sql که با فیچر
                          «بکاپ دیتابیس» بات (از تلگرام یا پنل وب) ساخته شده. درست
                          بعد از ساخته‌شدن جدول‌های تازه ایمپورت می‌شه، پس داده‌های
                          قدیمی با اسکیمای نصب جدید سازگار می‌مونن.
  --restore-source=PATH  مسیر (روی همین سرور) یک فایل source_backup_*.zip که با
                          فیچر «بکاپ سورس» بات ساخته شده. روی فایل‌های تازه دیپلوی‌شده
                          extract می‌شه (قبل از ساخته‌شدن جدول‌های دیتابیس)، config.php
                          همیشه دست‌نخورده می‌مونه.
  هر دو اختیاری هستن و می‌تونن با هم یا جدا استفاده بشن. همین فلگ‌ها بدون پیشوند
  "restore-" (--db=، --source=) روی دستور مستقل 'restore' هم کار می‌کنن، برای
  بازیابی بکاپ روی یه نصب موجود.

یک‌خطی از راه دور (بدون نیاز به فایل محلی) — تعاملی، سوال‌ها رو یکی‌یکی
دقیقاً مثل اجرای محلی می‌پرسه:
  curl -sL $INSTALL_SCRIPT_URL | sudo bash -s -- install

یک‌خطی از راه دور، کاملاً خودکار (همه‌ی جواب‌ها از قبل به‌صورت فلگ داده می‌شن):
  curl -sL $INSTALL_SCRIPT_URL | sudo bash -s -- install \\
      --domain=bot.example.com --token=BOT_TOKEN --admin=ADMIN_ID [--email=you@example.com] \\
      [--restore-db=/root/db_backup.sql] [--restore-source=/root/source_backup.zip]

  curl -sL $INSTALL_SCRIPT_URL | sudo bash -s -- update
  curl -sL $INSTALL_SCRIPT_URL | sudo bash -s -- info
  curl -sL $INSTALL_SCRIPT_URL | sudo bash -s -- restart
  curl -sL $INSTALL_SCRIPT_URL | sudo bash -s -- restore --db=/root/db_backup.sql --source=/root/source_backup.zip
  curl -sL $INSTALL_SCRIPT_URL | sudo bash -s -- uninstall --yes
USAGE
    fi
}

# ==============================================================================
#  دانلود سورس از گیت‌هاب (هم برای install و هم update استفاده می‌شه)
# ==============================================================================

# سورس پروژه رو توی یه پوشه‌ی موقت تازه دانلود می‌کنه و FETCHED_SRC_DIR رو ست می‌کنه.
fetch_source() {
    title "$(t fetch_downloading "$REPO_URL" "$REPO_BRANCH")"
    local tmp_dir
    tmp_dir="$(mktemp -d)"

    if command -v git >/dev/null 2>&1 \
        && git clone --depth 1 --branch "$REPO_BRANCH" "${REPO_URL}.git" "$tmp_dir" >/tmp/teamkan_clone.log 2>&1; then
        ok "$(t fetch_git_ok)"
    else
        warn "$(t fetch_git_fail)"
        rm -rf "$tmp_dir"; tmp_dir="$(mktemp -d)"
        local tarball_url="${REPO_URL}/archive/refs/heads/${REPO_BRANCH}.tar.gz"
        local tarball_file="/tmp/teamkan_src_$$.tar.gz"
        curl -fsSL "$tarball_url" -o "$tarball_file" 2>/tmp/teamkan_dl.log \
            || { err "$(t fetch_tarball_fail "$tarball_url")"; exit 1; }
        tar xzf "$tarball_file" -C "$tmp_dir" --strip-components=1 \
            || { err "$(t fetch_extract_fail)"; exit 1; }
        rm -f "$tarball_file"
        ok "$(t fetch_tarball_ok)"
    fi
    FETCHED_SRC_DIR="$tmp_dir"
}

# ==============================================================================
#  بخش نصب
# ==============================================================================

# مسیر داده‌شده برای --restore-db/--restore-source (یا معادل‌های تعاملیشون) رو
# اعتبارسنجی می‌کنه: باید وجود داشته باشه، قابل خواندن و غیرخالی باشه.
validate_restore_file() {
    local path="$1" label="$2"
    [[ -z "$path" ]] && return 0
    if [[ ! -f "$path" || ! -r "$path" ]]; then
        err "$(t restore_file_missing "$label" "$path")"
        return 1
    fi
    if [[ ! -s "$path" ]]; then
        err "$(t restore_file_empty "$label" "$path")"
        return 1
    fi
    return 0
}

collect_inputs() {
    title "$(t collect_title)"
    tty_read "$(t prompt_domain)" DOMAIN
    while [[ -z "$DOMAIN" ]]; do tty_read "$(t prompt_domain_retry)" DOMAIN; done

    tty_read "$(t prompt_token)" BOT_TOKEN
    while [[ -z "$BOT_TOKEN" ]]; do tty_read "$(t prompt_token_retry)" BOT_TOKEN; done

    tty_read "$(t prompt_admin)" ADMIN_ID
    while ! [[ "$ADMIN_ID" =~ ^[0-9]+$ ]]; do tty_read "$(t prompt_admin_retry)" ADMIN_ID; done

    tty_read "$(t prompt_ssl_email)" SSL_EMAIL

    tty_read "$(t prompt_restore_db)" RESTORE_DB_FILE
    while [[ -n "$RESTORE_DB_FILE" ]] && ! validate_restore_file "$RESTORE_DB_FILE" "$(t label_db_backup)"; do
        tty_read "$(t prompt_restore_db)" RESTORE_DB_FILE
    done

    tty_read "$(t prompt_restore_source)" RESTORE_SOURCE_FILE
    while [[ -n "$RESTORE_SOURCE_FILE" ]] && ! validate_restore_file "$RESTORE_SOURCE_FILE" "$(t label_source_backup)"; do
        tty_read "$(t prompt_restore_source)" RESTORE_SOURCE_FILE
    done

    finalize_install_vars
    echo
    info "$(t summary_title)"
    echo "  $(t lbl_domain)            $DOMAIN"
    echo "  $(t lbl_webroot)      $WEBROOT"
    echo "  $(t lbl_dbname)       $DB_NAME"
    [[ -n "$RESTORE_DB_FILE" ]]     && echo "  $(t lbl_restore_db)   $RESTORE_DB_FILE"
    [[ -n "$RESTORE_SOURCE_FILE" ]] && echo "  $(t lbl_restore_source)      $RESTORE_SOURCE_FILE"
    tty_read "$(t confirm_proceed)" CONFIRM
    if [[ "$CONFIRM" =~ ^[Nn]$ ]]; then
        err "$(t install_cancelled)"
        exit 1
    fi
}

# مسیر ورودی غیرتعاملی: --domain= --token= --admin= --email=
# --restore-db= --restore-source= رو پارس می‌کنه
parse_install_args() {
    DOMAIN=""; BOT_TOKEN=""; ADMIN_ID=""; SSL_EMAIL=""; RESTORE_DB_FILE=""; RESTORE_SOURCE_FILE=""
    for arg in "$@"; do
        case "$arg" in
            --domain=*)         DOMAIN="${arg#*=}" ;;
            --token=*)          BOT_TOKEN="${arg#*=}" ;;
            --admin=*)          ADMIN_ID="${arg#*=}" ;;
            --email=*)          SSL_EMAIL="${arg#*=}" ;;
            --restore-db=*)     RESTORE_DB_FILE="${arg#*=}" ;;
            --restore-source=*) RESTORE_SOURCE_FILE="${arg#*=}" ;;
            *) warn "$(t unknown_flag_ignored "$arg")" ;;
        esac
    done

    local missing=()
    [[ -z "$DOMAIN" ]]    && missing+=("--domain")
    [[ -z "$BOT_TOKEN" ]] && missing+=("--token")
    [[ -z "$ADMIN_ID" ]]  && missing+=("--admin")
    if [[ ${#missing[@]} -gt 0 ]]; then
        err "$(t missing_required_flags "${missing[*]}")"
        usage
        exit 1
    fi
    if ! [[ "$ADMIN_ID" =~ ^[0-9]+$ ]]; then
        err "$(t admin_must_be_number)"
        exit 1
    fi
    validate_restore_file "$RESTORE_DB_FILE" "$(t label_db_backup)" || exit 1
    validate_restore_file "$RESTORE_SOURCE_FILE" "$(t label_source_backup)" || exit 1

    finalize_install_vars
    info "$(t installing_with "$DOMAIN" "$ADMIN_ID" "${SSL_EMAIL:-$(t none_placeholder)}")"
    [[ -n "$RESTORE_DB_FILE" ]]     && info "$(t will_restore_db "$RESTORE_DB_FILE")"
    [[ -n "$RESTORE_SOURCE_FILE" ]] && info "$(t will_restore_source "$RESTORE_SOURCE_FILE")"
}

finalize_install_vars() {
    DB_NAME="teamkanbot"
    DB_USER="teamkanbot"
    DB_PASS="$(rand_pass)"
    WEBROOT="/var/www/$DOMAIN"
}

install_packages() {
    title "$(t installing_packages_title)"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq nginx mariadb-server curl git unzip zip cron \
        php-fpm php-mysql php-curl php-mbstring php-xml php-zip php-cli \
        certbot python3-certbot-nginx >/tmp/teamkan_apt.log 2>&1 \
        || { err "$(t packages_fail)"; exit 1; }
    ok "$(t packages_ok)"
}

detect_php_fpm() {
    PHP_FPM_SERVICE="$(systemctl list-units --type=service --all 2>/dev/null \
        | grep -oE 'php[0-9.]*-fpm\.service' | head -n1)"
    if [[ -z "$PHP_FPM_SERVICE" ]]; then
        err "$(t phpfpm_not_found)"
        exit 1
    fi
    systemctl enable --now "$PHP_FPM_SERVICE" >/dev/null 2>&1

    for i in 1 2 3 4 5; do
        PHP_FPM_SOCK="$(find /run/php -name 'php*-fpm.sock' 2>/dev/null | head -n1)"
        [[ -n "$PHP_FPM_SOCK" ]] && break
        sleep 1
    done
    if [[ -z "$PHP_FPM_SOCK" ]]; then
        err "$(t phpfpm_sock_not_found)"
        exit 1
    fi
}

setup_database() {
    title "$(t db_title)"
    systemctl enable --now mariadb >/dev/null 2>&1
    mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
    if [[ $? -ne 0 ]]; then
        err "$(t db_fail)"
        exit 1
    fi
    ok "$(t db_ok "$DB_NAME" "$DB_USER")"
}

# سورس دانلودشده از گیت‌هاب ($FETCHED_SRC_DIR) رو توی $WEBROOT کپی می‌کنه.
deploy_files() {
    title "$(t deploy_title "$WEBROOT")"
    mkdir -p "$WEBROOT"
    shopt -s dotglob nullglob
    for item in "$FETCHED_SRC_DIR"/*; do
        base="$(basename "$item")"
        case "$base" in
            install.sh|config.php|README.md|installer|.git|.github) continue ;;
        esac
        cp -r "$item" "$WEBROOT/"
    done
    shopt -u dotglob nullglob

    local safe_bot_token
    safe_bot_token="$(php_squote_escape "$BOT_TOKEN")"
    cat > "$WEBROOT/config.php" <<PHP
<?php
declare(strict_types=1);

define('BOT_TOKEN', '$safe_bot_token');
define('ADMIN_ID', $ADMIN_ID);

define('DB_HOST', 'localhost');
define('DB_NAME', '$DB_NAME');
define('DB_USER', '$DB_USER');
define('DB_PASS', '$DB_PASS');
PHP

    chown -R www-data:www-data "$WEBROOT"
    find "$WEBROOT" -type d -exec chmod 750 {} \;
    find "$WEBROOT" -type f -exec chmod 640 {} \;
    ok "$(t deploy_ok)"
}

# یه فایل source_backup_*.zip قبلی (که با فیچر «بکاپ سورس» خود بات ساخته شده) رو
# روی $WEBROOT استخراج می‌کنه. بعد از deploy_files اجرا می‌شه تا اگه کد سفارشی‌ای
# توی بکاپ باشه (مثلاً یه table.php متفاوت)، همون باشه که واقعاً اسکیما رو توی
# create_tables می‌سازه. config.php همیشه از استخراج کنار گذاشته می‌شه — بکاپ اصلاً
# شاملش نمی‌شه (createSourceBackupFile عمداً ردش می‌کنه) ولی این یه لایه‌ی محافظتی
# اضافه‌ست، چون credential های تازه‌ساخته‌شده توی $WEBROOT/config.php باید بمونن.
restore_source_backup() {
    [[ -z "${RESTORE_SOURCE_FILE:-}" ]] && return 0
    title "$(t restore_source_title)"
    if ! command -v unzip >/dev/null 2>&1; then
        err "$(t unzip_missing)"
        exit 1
    fi
    if ! unzip -o -q "$RESTORE_SOURCE_FILE" -d "$WEBROOT" -x 'config.php' >/tmp/teamkan_restore_source.log 2>&1; then
        err "$(t restore_source_fail)"
        exit 1
    fi
    chown -R www-data:www-data "$WEBROOT"
    find "$WEBROOT" -type d -exec chmod 750 {} \;
    find "$WEBROOT" -type f -exec chmod 640 {} \;
    ok "$(t restore_source_ok "$RESTORE_SOURCE_FILE")"
}

create_tables() {
    title "$(t tables_title)"
    php "$WEBROOT/table.php" >/tmp/teamkan_tables.log 2>&1
    if grep -qi "error\|خطا" /tmp/teamkan_tables.log; then
        err "$(t tables_fail)"
        exit 1
    fi
    ok "$(t tables_ok)"
}

# یه فایل db_backup_*.sql قبلی (که با فیچر «بکاپ دیتابیس» خود بات ساخته شده) رو
# روی جدول‌های تازه‌ساخته‌شده ایمپورت می‌کنه. این dump همیشه فقط شامل
# SET NAMES/SET FOREIGN_KEY_CHECKS و دستورات `INSERT IGNORE INTO` با اسم صریح
# ستون‌هاست (به createDbBackupFile() توی bot.php/webpanel.php نگاه کن) — هیچ
# DROP/CREATE/ALTER ای توش نیست — پس همیشه اجراش روی اسکیمای جدید امنه. --force
# باعث می‌شه mysql با وجود fail شدن یه ردیف (مثلاً یه ستون که از موقع گرفتن بکاپ
# حذف شده) متوقف نشه و ادامه بده، پس بکاپ‌های قدیمی‌تر با نصب‌های جدیدتر هم سازگار می‌مونن.
restore_db_backup() {
    [[ -z "${RESTORE_DB_FILE:-}" ]] && return 0
    title "$(t restore_db_title)"
    local log="/tmp/teamkan_restore_db.log"
    mysql --force -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$RESTORE_DB_FILE" 2>"$log"
    local failed_rows
    failed_rows="$(grep -c '^ERROR' "$log" 2>/dev/null || true)"
    if [[ "${failed_rows:-0}" -gt 0 ]]; then
        warn "$(t restore_db_partial "$failed_rows" "$log")"
    else
        ok "$(t restore_db_ok "$RESTORE_DB_FILE")"
    fi
}

setup_nginx() {
    title "$(t nginx_title)"
    detect_php_fpm

    cat > "/etc/nginx/sites-available/$DOMAIN" <<NGINX
server {
    listen 80;
    server_name $DOMAIN;
    root $WEBROOT;
    index webpanel.php;

    location ~ \.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$PHP_FPM_SOCK;
        # استخراج ساب (bot.php) گاهی چند ثانیه طول می‌کشه (اتصال به سرورهای ساب
        # خارجی)؛ timeout پیش‌فرض nginx (۶۰ ثانیه) رو بالا می‌بریم که وسط یه
        # استخراج کند، اتصال قطع نشه.
        fastcgi_read_timeout 90s;
        fastcgi_send_timeout 90s;
    }

    location ~ /\. { deny all; }
}
NGINX
    ln -sf "/etc/nginx/sites-available/$DOMAIN" "/etc/nginx/sites-enabled/$DOMAIN"
    if ! nginx -t >/tmp/teamkan_nginx.log 2>&1; then
        err "$(t nginx_invalid)"
        exit 1
    fi
    systemctl reload nginx
    ok "$(t nginx_ok)"
}

setup_ssl() {
    title "$(t ssl_title)"
    warn "$(t ssl_domain_warn "$DOMAIN")"
    local email_arg=(--register-unsafely-without-email)
    [[ -n "${SSL_EMAIL:-}" ]] && email_arg=(-m "$SSL_EMAIL")

    if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos "${email_arg[@]}" >/tmp/teamkan_certbot.log 2>&1; then
        ok "$(t ssl_ok)"
        SITE_URL="https://$DOMAIN"
    else
        warn "$(t ssl_fail1)"
        warn "$(t ssl_fail2)"
        warn "$(t ssl_fail3)"
        SITE_URL="http://$DOMAIN"
    fi
}

set_webhook() {
    title "$(t webhook_title)"
    local result
    result="$(curl -s "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook?url=${SITE_URL}/bot.php")"
    if echo "$result" | grep -q '"ok":true'; then
        ok "$(t webhook_ok "${SITE_URL}/bot.php")"
    else
        warn "$(t webhook_fail "$result")"
    fi
}

# پنل وب توی تب «⏱ کرون» از ادمین می‌خواد که خودش یه کرون‌جاب هاست رو دستی روی
# صدا زدن این آدرس هر ۱ دقیقه تنظیم کنه (طراحی‌شده برای هاست اشتراکی). روی یه
# VPS که خودمون نصبش می‌کنیم، لازم نیست دستی باشه — همینجا با crontab سیستم
# خودکارش می‌کنیم. توکن دقیقاً با همون فرمول getCronToken() توی bot.php ساخته
# می‌شه، پس با تنظیمات «⏱ کرون» که از تلگرام/پنل وب عوض می‌شه هماهنگ می‌مونه —
# فاصله‌ی واقعی ارسال بکاپ رو خود اپ کنترل می‌کنه، این کرون فقط هر دقیقه سرمی‌زنه.
setup_cron_job() {
    title "$(t cron_title)"
    local cron_token cron_url marker
    # BOT_TOKEN از طریق env به php -r پاس داده می‌شه (نه رشته‌سازیِ مستقیم توی سورس
    # PHP) که اگه توکن به هر دلیلی یه کوتیشن تک (') توش داشت، کد PHP خراب نشه.
    cron_token="$(BOT_TOKEN="$BOT_TOKEN" php -r "echo substr(hash('sha256', getenv('BOT_TOKEN') . '|cron_backup_secret_v1'), 0, 24);")"
    cron_url="${SITE_URL}/bot.php?action=cron_backup&token=${cron_token}"
    marker="# teamkan-bot-cron ($DOMAIN)"

    if ! { crontab -l 2>/dev/null | grep -vF "$marker"
           echo "* * * * * curl -fsS \"$cron_url\" >/dev/null 2>&1 $marker"
         } | crontab -; then
        warn "$(t cron_fail)"
        return
    fi

    systemctl enable --now cron >/dev/null 2>&1
    ok "$(t cron_ok)"
}

save_conf() {
    mkdir -p "$CONF_DIR"
    cat > "$CONF_FILE" <<CONF
DOMAIN="$DOMAIN"
WEBROOT="$WEBROOT"
DB_NAME="$DB_NAME"
DB_USER="$DB_USER"
DB_PASS="$DB_PASS"
SITE_URL="$SITE_URL"
APP_LANG="$APP_LANG"
CONF
    chmod 600 "$CONF_FILE"
}

# یه نسخه‌ی دائمی از این اسکریپت مدیریت ذخیره می‌کنه تا 'kanbot' بعداً هم کار کنه،
# فارغ از اینکه نصب اصلی از یه فایل محلی اجرا شده یا مستقیم از یه پایپ curl|bash
# (که در اون حالت هیچ فایل روی دیسکی برای symlink کردن وجود نداره).
install_management_symlink() {
    mkdir -p "$CONF_DIR"
    if curl -fsSL "$INSTALL_SCRIPT_URL" -o "$PERSIST_SCRIPT_PATH" 2>/tmp/teamkan_selfdl.log; then
        chmod +x "$PERSIST_SCRIPT_PATH"
    elif [[ -n "$SELF_PATH" && -f "$SELF_PATH" ]]; then
        cp "$SELF_PATH" "$PERSIST_SCRIPT_PATH"
        chmod +x "$PERSIST_SCRIPT_PATH"
    else
        warn "$(t symlink_fail)"
        return
    fi
    ln -sf "$PERSIST_SCRIPT_PATH" /usr/local/bin/kanbot
    ok "$(t symlink_ok)"
}

do_install_steps() {
    install_packages
    setup_database
    fetch_source
    deploy_files
    restore_source_backup
    create_tables
    restore_db_backup
    setup_nginx
    setup_ssl
    set_webhook
    setup_cron_job
    save_conf
    install_management_symlink
    [[ -n "${FETCHED_SRC_DIR:-}" ]] && rm -rf "$FETCHED_SRC_DIR"

    title "$(t install_done_title)"
    echo -e "${C_GREEN}$(t lbl_panel_url)${C_RESET} ${SITE_URL}/webpanel.php"
    echo -e "${C_GREEN}$(t lbl_default_pass)${C_RESET} admin  ${C_YELLOW}$(t default_pass_warn)${C_RESET}"
    echo -e "${C_GREEN}$(t lbl_manage_next)${C_RESET} $(t manage_next_text "${C_BOLD}sudo kanbot${C_RESET}")"
    echo -e "            $(t manage_next_text2 "${C_BOLD}sudo kanbot update|info|status|restart|uninstall${C_RESET}")"
    echo -e "${C_GREEN}$(t lbl_conf_saved)${C_RESET} $CONF_FILE"
    [[ -n "${RESTORE_DB_FILE:-}" || -n "${RESTORE_SOURCE_FILE:-}" ]] && \
        echo -e "${C_GREEN}$(t lbl_restored_from)${C_RESET} ${RESTORE_DB_FILE:-$(t no_db_placeholder)}  ${RESTORE_SOURCE_FILE:-$(t no_source_placeholder)}"
}

run_install() {
    require_root
    require_apt
    if [[ $# -eq 0 ]]; then
        # هیچ فلگی داده نشده: سوال‌ها رو تعاملی می‌پرسه. این از /dev/tty می‌خونه،
        # پس حتی وقتی از طریق curl | sudo bash اجرا بشه هم درست کار می‌کنه.
        [[ -z "$APP_LANG_EXPLICIT" ]] && select_language_interactive
        collect_inputs
    else
        parse_install_args "$@"
    fi
    do_install_steps
}

# ==============================================================================
#  بخش مدیریت (وقتی بات از قبل نصب شده باشه اجرا می‌شه)
# ==============================================================================

load_conf() {
    if [[ ! -f "$CONF_FILE" ]]; then
        err "$(t conf_not_found "$CONF_FILE")"
        exit 1
    fi
    local explicit="$APP_LANG_EXPLICIT" override="$APP_LANG"
    # shellcheck disable=SC1090
    source "$CONF_FILE"
    # اگه کاربر همین اجرا --lang داده باشه، به جای زبانِ ذخیره‌شده توی کانفیگ
    # همونو نگه می‌داریم (override موقت فقط برای همین دستور).
    [[ -n "$explicit" ]] && APP_LANG="$override"
}

find_phpfpm_service() {
    systemctl list-units --type=service --all 2>/dev/null | grep -oE 'php[0-9.]*-fpm\.service' | head -n1
}

mgmt_status() {
    title "$(t status_title)"
    for s in nginx mariadb; do
        systemctl is-active --quiet "$s" && ok "$(t status_active "$s")" || err "$(t status_inactive "$s")"
    done
    local phpfpm
    phpfpm="$(find_phpfpm_service)"
    [[ -n "$phpfpm" ]] && { systemctl is-active --quiet "$phpfpm" && ok "$(t status_active "$phpfpm")" || err "$(t status_inactive "$phpfpm")"; }
}

mgmt_restart_bot() {
    title "$(t restart_bot_title)"
    local phpfpm
    phpfpm="$(find_phpfpm_service)"
    [[ -n "$phpfpm" ]] && systemctl restart "$phpfpm" && ok "$(t restarted "$phpfpm")"
    systemctl restart nginx && ok "$(t restarted "nginx")"
}

mgmt_restart_all() {
    title "$(t restart_all_title)"
    mgmt_restart_bot
    systemctl restart mariadb && ok "$(t restarted "mariadb")"
}

mgmt_info() {
    title "$(t info_title)"
    echo "$(t lbl_domain)              $DOMAIN"
    echo "$(t lbl_panel_url)           ${SITE_URL}/webpanel.php"
    echo "$(t lbl_webroot)        $WEBROOT"
    echo "$(t lbl_dbname)         $DB_NAME"
    echo "$(t lbl_dbuser)        $DB_USER"
}

mgmt_backup() {
    title "$(t backup_title)"
    mkdir -p "$BACKUP_DIR"
    local out="$BACKUP_DIR/db_$(date +%Y-%m-%d_%H-%M-%S).sql"
    if mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$out" 2>/tmp/teamkan_backup.log; then
        ok "$(t backup_saved "$out")"
    else
        err "$(t backup_fail)"
    fi
}

# یه بکاپ قبلی از دیتابیس و/یا سورس رو روی همین نصب موجود برمی‌گردونه، با همون
# منطق restore_db_backup()/restore_source_backup() که موقع نصب تازه استفاده
# می‌شه. --db=PATH / --source=PATH رو قبول می‌کنه؛ اگه بدون فلگ صدا زده بشه
# (مثلاً از منو)، به پرسیدن تعاملی برمی‌گرده.
mgmt_restore() {
    RESTORE_DB_FILE=""; RESTORE_SOURCE_FILE=""
    for arg in "$@"; do
        case "$arg" in
            --db=*)     RESTORE_DB_FILE="${arg#*=}" ;;
            --source=*) RESTORE_SOURCE_FILE="${arg#*=}" ;;
            *) warn "$(t unknown_flag_ignored "$arg")" ;;
        esac
    done

    if [[ $# -eq 0 ]]; then
        tty_read "$(t prompt_restore_db)" RESTORE_DB_FILE
        tty_read "$(t prompt_restore_source)" RESTORE_SOURCE_FILE
    fi

    if [[ -z "$RESTORE_DB_FILE" && -z "$RESTORE_SOURCE_FILE" ]]; then
        warn "$(t restore_nothing)"
        return
    fi

    validate_restore_file "$RESTORE_DB_FILE" "$(t label_db_backup)" || return
    validate_restore_file "$RESTORE_SOURCE_FILE" "$(t label_source_backup)" || return

    title "$(t restore_existing_title)"
    warn "$(t restore_existing_warn)"
    restore_source_backup
    restore_db_backup
    mgmt_restart_bot
    ok "$(t restore_done)"
}

mgmt_rewebhook() {
    title "$(t rewebhook_title)"
    local token
    token="$(php -r "require '$WEBROOT/config.php'; echo BOT_TOKEN;")"
    local result
    result="$(curl -s "https://api.telegram.org/bot${token}/setWebhook?url=${SITE_URL}/bot.php")"
    echo "$result" | grep -q '"ok":true' && ok "$(t rewebhook_ok)" || warn "$(t telegram_response "$result")"
}

mgmt_reset_panel_password() {
    title "$(t reset_pass_title)"
    tty_read "$(t prompt_new_pass)" NEWPASS silent
    if [[ -z "$NEWPASS" ]]; then err "$(t pass_empty)"; return; fi
    local hash
    hash="$(NEWPASS="$NEWPASS" php -r "echo password_hash(getenv('NEWPASS'), PASSWORD_DEFAULT);")"
    mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
        -e "INSERT INTO settings (setting_key, setting_value) VALUES ('webpanel_password_hash', '$hash') ON DUPLICATE KEY UPDATE setting_value='$hash';" \
        && ok "$(t pass_changed)" \
        || err "$(t pass_update_fail)"
}

mgmt_ssl_renew() {
    title "$(t ssl_renew_title)"
    certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email \
        && ok "$(t ssl_renew_ok)" \
        || err "$(t ssl_renew_fail)"
}

# کاملاً خودکار: آخرین کد رو مستقیم از گیت‌هاب می‌کشه و دوباره دیپلوی می‌کنه.
# بدون سوال، پس چه محلی چه از طریق curl | sudo bash -s -- update یکسان کار می‌کنه.
mgmt_update_bot() {
    title "$(t update_title)"
    fetch_source

    if [[ ! -f "$FETCHED_SRC_DIR/bot.php" ]]; then
        err "$(t update_no_botphp)"
        rm -rf "$FETCHED_SRC_DIR"
        return 1
    fi

    mkdir -p "$BACKUP_DIR"
    local backup_file="$BACKUP_DIR/webroot_before_update_$(date +%Y-%m-%d_%H-%M-%S).tar.gz"
    tar czf "$backup_file" -C "$(dirname "$WEBROOT")" "$(basename "$WEBROOT")" 2>/dev/null
    ok "$(t update_backup_ok "$backup_file")"

    shopt -s dotglob nullglob
    for item in "$FETCHED_SRC_DIR"/*; do
        base="$(basename "$item")"
        case "$base" in
            install.sh|config.php|README.md|installer|.git|.github) continue ;;
        esac
        cp -rf "$item" "$WEBROOT/"
    done
    shopt -u dotglob nullglob

    chown -R www-data:www-data "$WEBROOT"
    find "$WEBROOT" -type d -exec chmod 750 {} \;
    find "$WEBROOT" -type f -exec chmod 640 {} \;

    php "$WEBROOT/table.php" >/tmp/teamkan_tables.log 2>&1

    rm -rf "$FETCHED_SRC_DIR"

    # خودِ اسکریپت مدیریت (دستور kanbot) هم باید آپدیت بشه، وگرنه هر چقدر هم بات
    # آپدیت بشه، منوی kanbot برای همیشه روی همون نسخه‌ای که اول باهاش نصب شده
    # قفل می‌مونه (مثلاً اگه فارسی‌سازی منو بعد از نصب اضافه شده باشه).
    install_management_symlink

    mgmt_restart_bot
    ok "$(t update_done "$REPO_BRANCH")"
}

# $1 ممکنه --yes باشه تا تأیید رو رد کنه (برای اجراهای curl|bash لازمه).
mgmt_uninstall() {
    local force="${1:-}"
    title "$(t uninstall_title)"
    warn "$(t uninstall_warn "$WEBROOT" "$DB_NAME" "$DOMAIN")"

    if [[ "$force" != "--yes" ]]; then
        tty_read "$(t prompt_confirm_delete)" CONFIRM
        if [[ "$CONFIRM" != "DELETE" ]]; then
            info "$(t cancelled)"
            return
        fi
    fi

    rm -rf "$WEBROOT"
    rm -f "/etc/nginx/sites-enabled/$DOMAIN" "/etc/nginx/sites-available/$DOMAIN"
    systemctl reload nginx 2>/dev/null
    mysql -u root -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; DROP USER IF EXISTS '$DB_USER'@'localhost'; FLUSH PRIVILEGES;" 2>/dev/null
    rm -f /usr/local/bin/kanbot
    rm -rf "$CONF_DIR"
    ok "$(t uninstall_done)"
    exit 0
}

# زبان رو تعاملی از منو عوض می‌کنه و توی $CONF_FILE ذخیره می‌کنه تا اجراهای
# بعدی 'sudo kanbot' هم همون زبان رو به خاطر بسپارن.
mgmt_change_language() {
    title "$(t change_lang_title)"
    echo "  1) فارسی"
    echo "  2) English"
    local choice
    read -rp "$(t prompt_menu_choice)" choice
    case "$choice" in
        1) APP_LANG="fa" ;;
        2) APP_LANG="en" ;;
        *) warn "$(t invalid_choice)"; return ;;
    esac
    save_conf
    ok "$(t lang_changed)"
}

show_menu() {
    load_conf
    while true; do
        local langname
        [[ "$APP_LANG" == "en" ]] && langname="English" || langname="فارسی"
        echo
        echo -e "${C_BOLD}${C_BLUE}=============================================${C_RESET}"
        echo -e "${C_BOLD}${C_BLUE}   $(t menu_header)   ($DOMAIN)${C_RESET}"
        echo -e "${C_BOLD}${C_BLUE}=============================================${C_RESET}"
        echo "$(t menu_1)"
        echo "$(t menu_2)"
        echo "$(t menu_3)"
        echo "$(t menu_4)"
        echo "$(t menu_5)"
        echo "$(t menu_6)"
        echo "$(t menu_7)"
        echo "$(t menu_8)"
        echo "$(t menu_9)"
        echo "$(t menu_10)"
        echo "$(t menu_11)"
        echo "$(t menu_12) ($langname)"
        echo "$(t menu_0)"
        read -rp "$(t prompt_menu_choice)" CH
        case "$CH" in
            1) mgmt_status ;;
            2) mgmt_restart_bot ;;
            3) mgmt_restart_all ;;
            4) mgmt_update_bot ;;
            5) mgmt_info ;;
            6) mgmt_backup ;;
            7) mgmt_restore ;;
            8) mgmt_rewebhook ;;
            9) mgmt_reset_panel_password ;;
            10) mgmt_ssl_renew ;;
            11) mgmt_uninstall ;;
            12) mgmt_change_language ;;
            0) exit 0 ;;
            *) warn "$(t invalid_choice)" ;;
        esac
        pause
    done
}

# ==============================================================================
#  نقطه‌ی ورود
# ==============================================================================
main() {
    # اگه --lang قبل از اسم دستور اومده باشه (مثلاً install.sh --lang=en install)
    # هم پشتیبانی می‌شه، نه فقط بعدش.
    while [[ "${1:-}" == --lang=* ]]; do
        parse_and_strip_lang "$1"
        shift
    done

    local cmd="${1:-auto}"
    [[ $# -gt 0 ]] && shift
    parse_and_strip_lang "$@"
    set -- "${REMAINING_ARGS[@]}"

    case "$cmd" in
        install)
            run_install "$@"
            ;;
        update)
            require_root; load_conf; mgmt_update_bot
            ;;
        info)
            require_root; load_conf; mgmt_info
            ;;
        status)
            require_root; load_conf; mgmt_status
            ;;
        restart)
            require_root; load_conf; mgmt_restart_all
            ;;
        restore)
            require_root; load_conf; mgmt_restore "$@"
            ;;
        uninstall)
            require_root; load_conf; mgmt_uninstall "${1:-}"
            ;;
        menu)
            require_root; show_menu
            ;;
        auto)
            require_root
            if [[ -f "$CONF_FILE" ]]; then
                show_menu
            else
                require_apt
                run_install
            fi
            ;;
        -h|--help|help)
            usage
            ;;
        *)
            err "$(t unknown_cmd "$cmd")"
            usage
            exit 1
            ;;
    esac
}

main "$@"
