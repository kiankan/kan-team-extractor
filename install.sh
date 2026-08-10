#!/usr/bin/env bash
# ==============================================================================
#  install.sh — نصب‌کننده و ابزار مدیریت از راه دور برای بات تیم کن.
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
        err "این اسکریپت باید با دسترسی root اجرا بشه. مثال: sudo bash install.sh"
        exit 1
    fi
}

require_apt() {
    if ! command -v apt-get >/dev/null 2>&1; then
        err "این نصب‌کننده فقط از توزیع‌های مبتنی بر Debian/Ubuntu (apt) پشتیبانی می‌کنه."
        exit 1
    fi
}

pause() { read -rp "برای ادامه Enter را بزنید..." _ < /dev/tty 2>/dev/null || read -rp "برای ادامه Enter را بزنید..." _; }

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
        err "ترمینال تعاملی برای دریافت ورودی در دسترس نیست: $__prompt"
        err "به‌جاش با فلگ‌های مناسب دوباره اجرا کن (به install.sh --help نگاه کن)."
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

usage() {
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
}

# ==============================================================================
#  دانلود سورس از گیت‌هاب (هم برای install و هم update استفاده می‌شه)
# ==============================================================================

# سورس پروژه رو توی یه پوشه‌ی موقت تازه دانلود می‌کنه و FETCHED_SRC_DIR رو ست می‌کنه.
fetch_source() {
    title "در حال دانلود سورس از گیت‌هاب ($REPO_URL، شاخه: $REPO_BRANCH)"
    local tmp_dir
    tmp_dir="$(mktemp -d)"

    if command -v git >/dev/null 2>&1 \
        && git clone --depth 1 --branch "$REPO_BRANCH" "${REPO_URL}.git" "$tmp_dir" >/tmp/teamkan_clone.log 2>&1; then
        ok "سورس با git کلون شد."
    else
        warn "git clone در دسترس نیست یا شکست خورد؛ می‌ریم سراغ دانلود tarball."
        rm -rf "$tmp_dir"; tmp_dir="$(mktemp -d)"
        local tarball_url="${REPO_URL}/archive/refs/heads/${REPO_BRANCH}.tar.gz"
        local tarball_file="/tmp/teamkan_src_$$.tar.gz"
        curl -fsSL "$tarball_url" -o "$tarball_file" 2>/tmp/teamkan_dl.log \
            || { err "دانلود سورس از $tarball_url شکست خورد (جزئیات: /tmp/teamkan_dl.log)"; exit 1; }
        tar xzf "$tarball_file" -C "$tmp_dir" --strip-components=1 \
            || { err "استخراج آرشیو سورس شکست خورد."; exit 1; }
        rm -f "$tarball_file"
        ok "سورس با tarball دانلود شد."
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
        err "فایل $label پیدا نشد یا قابل خواندن نیست: $path"
        return 1
    fi
    if [[ ! -s "$path" ]]; then
        err "فایل $label خالیه: $path"
        return 1
    fi
    return 0
}

collect_inputs() {
    title "در حال جمع‌آوری اطلاعات نصب"
    tty_read "دامنه‌ای که از قبل به این سرور اشاره می‌کنه (مثلاً bot.example.com): " DOMAIN
    while [[ -z "$DOMAIN" ]]; do tty_read "دامنه نمی‌تونه خالی باشه. دوباره وارد کن: " DOMAIN; done

    tty_read "توکن ربات (از @BotFather): " BOT_TOKEN
    while [[ -z "$BOT_TOKEN" ]]; do tty_read "توکن نمی‌تونه خالی باشه. دوباره وارد کن: " BOT_TOKEN; done

    tty_read "آیدی عددی مدیر اصلی (از @userinfobot): " ADMIN_ID
    while ! [[ "$ADMIN_ID" =~ ^[0-9]+$ ]]; do tty_read "فقط باید عدد باشه. دوباره وارد کن: " ADMIN_ID; done

    tty_read "ایمیلت برای گواهی SSL (Let's Encrypt) [اختیاری، برای رد شدن Enter بزن]: " SSL_EMAIL

    tty_read "مسیر یک بکاپ دیتابیس قبلی (.sql) برای بازیابی [اختیاری، برای رد شدن Enter بزن]: " RESTORE_DB_FILE
    while [[ -n "$RESTORE_DB_FILE" ]] && ! validate_restore_file "$RESTORE_DB_FILE" "بکاپ دیتابیس"; do
        tty_read "مسیر یک بکاپ دیتابیس قبلی (.sql) برای بازیابی [اختیاری، برای رد شدن Enter بزن]: " RESTORE_DB_FILE
    done

    tty_read "مسیر یک بکاپ سورس قبلی (.zip) برای بازیابی [اختیاری، برای رد شدن Enter بزن]: " RESTORE_SOURCE_FILE
    while [[ -n "$RESTORE_SOURCE_FILE" ]] && ! validate_restore_file "$RESTORE_SOURCE_FILE" "بکاپ سورس"; do
        tty_read "مسیر یک بکاپ سورس قبلی (.zip) برای بازیابی [اختیاری، برای رد شدن Enter بزن]: " RESTORE_SOURCE_FILE
    done

    finalize_install_vars
    echo
    info "خلاصه:"
    echo "  دامنه:            $DOMAIN"
    echo "  مسیر فایل‌ها:      $WEBROOT"
    echo "  نام دیتابیس:       $DB_NAME"
    [[ -n "$RESTORE_DB_FILE" ]]     && echo "  بازیابی دیتابیس:   $RESTORE_DB_FILE"
    [[ -n "$RESTORE_SOURCE_FILE" ]] && echo "  بازیابی سورس:      $RESTORE_SOURCE_FILE"
    tty_read "همه‌چیز درسته؟ ادامه بدیم؟ [Y/n]: " CONFIRM
    if [[ "$CONFIRM" =~ ^[Nn]$ ]]; then
        err "نصب لغو شد."
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
            *) warn "گزینه‌ی ناشناخته نادیده گرفته شد: $arg" ;;
        esac
    done

    local missing=()
    [[ -z "$DOMAIN" ]]    && missing+=("--domain")
    [[ -z "$BOT_TOKEN" ]] && missing+=("--token")
    [[ -z "$ADMIN_ID" ]]  && missing+=("--admin")
    if [[ ${#missing[@]} -gt 0 ]]; then
        err "گزینه‌های اجباری وارد نشدن: ${missing[*]}"
        usage
        exit 1
    fi
    if ! [[ "$ADMIN_ID" =~ ^[0-9]+$ ]]; then
        err "--admin فقط باید عدد باشه."
        exit 1
    fi
    validate_restore_file "$RESTORE_DB_FILE" "بکاپ دیتابیس" || exit 1
    validate_restore_file "$RESTORE_SOURCE_FILE" "بکاپ سورس" || exit 1

    finalize_install_vars
    info "در حال نصب با: دامنه=$DOMAIN ادمین=$ADMIN_ID ایمیل=${SSL_EMAIL:-<هیچ‌کدام>}"
    [[ -n "$RESTORE_DB_FILE" ]]     && info "بکاپ دیتابیس بازیابی می‌شه: $RESTORE_DB_FILE"
    [[ -n "$RESTORE_SOURCE_FILE" ]] && info "بکاپ سورس بازیابی می‌شه: $RESTORE_SOURCE_FILE"
}

finalize_install_vars() {
    DB_NAME="teamkanbot"
    DB_USER="teamkanbot"
    DB_PASS="$(rand_pass)"
    WEBROOT="/var/www/$DOMAIN"
}

install_packages() {
    title "در حال نصب پیش‌نیازها (nginx، PHP-FPM، MariaDB و ...)"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq nginx mariadb-server curl git unzip zip cron \
        php-fpm php-mysql php-curl php-mbstring php-xml php-zip php-cli \
        certbot python3-certbot-nginx >/tmp/teamkan_apt.log 2>&1 \
        || { err "نصب پکیج‌ها شکست خورد. جزئیات: /tmp/teamkan_apt.log"; exit 1; }
    ok "پیش‌نیازها نصب شدند."
}

detect_php_fpm() {
    PHP_FPM_SERVICE="$(systemctl list-units --type=service --all 2>/dev/null \
        | grep -oE 'php[0-9.]*-fpm\.service' | head -n1)"
    if [[ -z "$PHP_FPM_SERVICE" ]]; then
        err "سرویس php-fpm پیدا نشد."
        exit 1
    fi
    systemctl enable --now "$PHP_FPM_SERVICE" >/dev/null 2>&1

    for i in 1 2 3 4 5; do
        PHP_FPM_SOCK="$(find /run/php -name 'php*-fpm.sock' 2>/dev/null | head -n1)"
        [[ -n "$PHP_FPM_SOCK" ]] && break
        sleep 1
    done
    if [[ -z "$PHP_FPM_SOCK" ]]; then
        err "سوکت php-fpm پیدا نشد."
        exit 1
    fi
}

setup_database() {
    title "در حال ساخت دیتابیس"
    systemctl enable --now mariadb >/dev/null 2>&1
    mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
    if [[ $? -ne 0 ]]; then
        err "ساخت دیتابیس شکست خورد. آیا MariaDB درست نصب شده؟"
        exit 1
    fi
    ok "دیتابیس '$DB_NAME' و یوزر '$DB_USER' ساخته شدند."
}

# سورس دانلودشده از گیت‌هاب ($FETCHED_SRC_DIR) رو توی $WEBROOT کپی می‌کنه.
deploy_files() {
    title "در حال کپی فایل‌های پروژه به $WEBROOT"
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
    ok "فایل‌ها کپی شدند و config.php ساخته شد."
}

# یه فایل source_backup_*.zip قبلی (که با فیچر «بکاپ سورس» خود بات ساخته شده) رو
# روی $WEBROOT استخراج می‌کنه. بعد از deploy_files اجرا می‌شه تا اگه کد سفارشی‌ای
# توی بکاپ باشه (مثلاً یه table.php متفاوت)، همون باشه که واقعاً اسکیما رو توی
# create_tables می‌سازه. config.php همیشه از استخراج کنار گذاشته می‌شه — بکاپ اصلاً
# شاملش نمی‌شه (createSourceBackupFile عمداً ردش می‌کنه) ولی این یه لایه‌ی محافظتی
# اضافه‌ست، چون credential های تازه‌ساخته‌شده توی $WEBROOT/config.php باید بمونن.
restore_source_backup() {
    [[ -z "${RESTORE_SOURCE_FILE:-}" ]] && return 0
    title "در حال بازیابی بکاپ سورس قبلی"
    if ! command -v unzip >/dev/null 2>&1; then
        err "unzip در دسترس نیست؛ نمی‌شه بکاپ سورس رو بازیابی کرد."
        exit 1
    fi
    if ! unzip -o -q "$RESTORE_SOURCE_FILE" -d "$WEBROOT" -x 'config.php' >/tmp/teamkan_restore_source.log 2>&1; then
        err "استخراج بکاپ سورس شکست خورد. جزئیات: /tmp/teamkan_restore_source.log"
        exit 1
    fi
    chown -R www-data:www-data "$WEBROOT"
    find "$WEBROOT" -type d -exec chmod 750 {} \;
    find "$WEBROOT" -type f -exec chmod 640 {} \;
    ok "بکاپ سورس از این فایل بازیابی شد: $RESTORE_SOURCE_FILE"
}

create_tables() {
    title "در حال ساخت جدول‌های دیتابیس"
    php "$WEBROOT/table.php" >/tmp/teamkan_tables.log 2>&1
    if grep -qi "error\|خطا" /tmp/teamkan_tables.log; then
        err "ساخت جدول‌ها شکست خورد. جزئیات: /tmp/teamkan_tables.log"
        exit 1
    fi
    ok "جدول‌های دیتابیس ساخته شدند."
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
    title "در حال بازیابی بکاپ دیتابیس قبلی"
    local log="/tmp/teamkan_restore_db.log"
    mysql --force -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" < "$RESTORE_DB_FILE" 2>"$log"
    local failed_rows
    failed_rows="$(grep -c '^ERROR' "$log" 2>/dev/null || true)"
    if [[ "${failed_rows:-0}" -gt 0 ]]; then
        warn "بکاپ دیتابیس بازیابی شد، ولی $failed_rows ردیف به‌خاطر تفاوت اسکیما رد شدن. جزئیات: $log"
    else
        ok "بکاپ دیتابیس از این فایل بازیابی شد: $RESTORE_DB_FILE"
    fi
}

setup_nginx() {
    title "در حال تنظیم Nginx"
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
        err "کانفیگ Nginx نامعتبره. جزئیات: /tmp/teamkan_nginx.log"
        exit 1
    fi
    systemctl reload nginx
    ok "Nginx تنظیم شد (HTTP)."
}

setup_ssl() {
    title "در حال دریافت گواهی SSL رایگان (Let's Encrypt)"
    warn "برای موفقیت این مرحله، $DOMAIN باید از قبل به آی‌پی همین سرور اشاره کنه."
    local email_arg=(--register-unsafely-without-email)
    [[ -n "${SSL_EMAIL:-}" ]] && email_arg=(-m "$SSL_EMAIL")

    if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos "${email_arg[@]}" >/tmp/teamkan_certbot.log 2>&1; then
        ok "گواهی SSL با موفقیت دریافت و فعال شد."
        SITE_URL="https://$DOMAIN"
    else
        warn "دریافت SSL شکست خورد (جزئیات: /tmp/teamkan_certbot.log). فعلاً روی HTTP ادامه می‌دیم؛"
        warn "بعد از اینکه DNS دامنه درست تنظیم شد، 'kanbot' رو اجرا کن و گزینه‌ی تمدید SSL رو بزن، یا 'sudo kanbot menu'."
        warn "توجه: تلگرام فقط HTTPS رو برای وب‌هوک قبول می‌کنه، پس بات در دسترس نخواهد بود تا وقتی SSL درست بشه."
        SITE_URL="http://$DOMAIN"
    fi
}

set_webhook() {
    title "در حال تنظیم وب‌هوک تلگرام"
    local result
    result="$(curl -s "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook?url=${SITE_URL}/bot.php")"
    if echo "$result" | grep -q '"ok":true'; then
        ok "وب‌هوک با موفقیت روی ${SITE_URL}/bot.php ست شد"
    else
        warn "تنظیم وب‌هوک شکست خورد. پاسخ تلگرام: $result"
    fi
}

# پنل وب توی تب «⏱ کرون» از ادمین می‌خواد که خودش یه کرون‌جاب هاست رو دستی روی
# صدا زدن این آدرس هر ۱ دقیقه تنظیم کنه (طراحی‌شده برای هاست اشتراکی). روی یه
# VPS که خودمون نصبش می‌کنیم، لازم نیست دستی باشه — همینجا با crontab سیستم
# خودکارش می‌کنیم. توکن دقیقاً با همون فرمول getCronToken() توی bot.php ساخته
# می‌شه، پس با تنظیمات «⏱ کرون» که از تلگرام/پنل وب عوض می‌شه هماهنگ می‌مونه —
# فاصله‌ی واقعی ارسال بکاپ رو خود اپ کنترل می‌کنه، این کرون فقط هر دقیقه سرمی‌زنه.
setup_cron_job() {
    title "در حال تنظیم خودکار کرون‌جاب بکاپ"
    local cron_token cron_url marker
    # BOT_TOKEN از طریق env به php -r پاس داده می‌شه (نه رشته‌سازیِ مستقیم توی سورس
    # PHP) که اگه توکن به هر دلیلی یه کوتیشن تک (') توش داشت، کد PHP خراب نشه.
    cron_token="$(BOT_TOKEN="$BOT_TOKEN" php -r "echo substr(hash('sha256', getenv('BOT_TOKEN') . '|cron_backup_secret_v1'), 0, 24);")"
    cron_url="${SITE_URL}/bot.php?action=cron_backup&token=${cron_token}"
    marker="# teamkan-bot-cron ($DOMAIN)"

    if ! { crontab -l 2>/dev/null | grep -vF "$marker"
           echo "* * * * * curl -fsS \"$cron_url\" >/dev/null 2>&1 $marker"
         } | crontab -; then
        warn "تنظیم خودکار کرون‌جاب شکست خورد؛ باید دستی crontab رو تنظیم کنی (crontab -e)."
        return
    fi

    systemctl enable --now cron >/dev/null 2>&1
    ok "کرون‌جاب خودکار تنظیم شد (هر ۱ دقیقه سرمی‌زنه؛ فاصله‌ی واقعی ارسال بکاپ رو از تب «⏱ کرون» توی پنل وب یا داخل خود ربات تغییر بده)."
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
        warn "نتونستم یه نسخه‌ی دائمی از اسکریپت مدیریت رو ذخیره کنم؛ دستور 'kanbot' در دسترس نخواهد بود."
        return
    fi
    ln -sf "$PERSIST_SCRIPT_PATH" /usr/local/bin/kanbot
    ok "دستور مدیریت نصب شد: هر وقت خواستی با 'sudo kanbot' منو رو باز کن."
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

    title "نصب کامل شد 🎉"
    echo -e "${C_GREEN}آدرس پنل وب:${C_RESET} ${SITE_URL}/webpanel.php"
    echo -e "${C_GREEN}رمز پیش‌فرض پنل:${C_RESET} admin  ${C_YELLOW}(همین الان از تب 'Security' عوضش کن)${C_RESET}"
    echo -e "${C_GREEN}برای مدیریت بعدی:${C_RESET} دستور ${C_BOLD}sudo kanbot${C_RESET} رو بزن تا منوی مدیریت باز بشه،"
    echo -e "            یا مستقیم ${C_BOLD}sudo kanbot update|info|status|restart|uninstall${C_RESET} رو بزن."
    echo -e "${C_GREEN}اطلاعات نصب ذخیره شد در:${C_RESET} $CONF_FILE"
    [[ -n "${RESTORE_DB_FILE:-}" || -n "${RESTORE_SOURCE_FILE:-}" ]] && \
        echo -e "${C_GREEN}بازیابی‌شده از بکاپ:${C_RESET} ${RESTORE_DB_FILE:-<بدون دیتابیس>}  ${RESTORE_SOURCE_FILE:-<بدون سورس>}"
}

run_install() {
    require_root
    require_apt
    if [[ $# -eq 0 ]]; then
        # هیچ فلگی داده نشده: سوال‌ها رو تعاملی می‌پرسه. این از /dev/tty می‌خونه،
        # پس حتی وقتی از طریق curl | sudo bash اجرا بشه هم درست کار می‌کنه.
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
        err "فایل کانفیگ نصب پیدا نشد ($CONF_FILE). اول باید نصب‌کننده رو اجرا کنی."
        exit 1
    fi
    # shellcheck disable=SC1090
    source "$CONF_FILE"
}

find_phpfpm_service() {
    systemctl list-units --type=service --all 2>/dev/null | grep -oE 'php[0-9.]*-fpm\.service' | head -n1
}

mgmt_status() {
    title "وضعیت سرویس‌ها"
    for s in nginx mariadb; do
        systemctl is-active --quiet "$s" && ok "$s: فعال" || err "$s: غیرفعال"
    done
    local phpfpm
    phpfpm="$(find_phpfpm_service)"
    [[ -n "$phpfpm" ]] && { systemctl is-active --quiet "$phpfpm" && ok "$phpfpm: فعال" || err "$phpfpm: غیرفعال"; }
}

mgmt_restart_bot() {
    title "در حال ری‌استارت بات (PHP-FPM + Nginx)"
    local phpfpm
    phpfpm="$(find_phpfpm_service)"
    [[ -n "$phpfpm" ]] && systemctl restart "$phpfpm" && ok "$phpfpm ری‌استارت شد."
    systemctl restart nginx && ok "nginx ری‌استارت شد."
}

mgmt_restart_all() {
    title "در حال ری‌استارت همه‌ی سرویس‌ها"
    mgmt_restart_bot
    systemctl restart mariadb && ok "mariadb ری‌استارت شد."
}

mgmt_info() {
    title "اطلاعات نصب"
    echo "دامنه:              $DOMAIN"
    echo "آدرس پنل:           ${SITE_URL}/webpanel.php"
    echo "مسیر فایل‌ها:        $WEBROOT"
    echo "نام دیتابیس:         $DB_NAME"
    echo "یوزر دیتابیس:        $DB_USER"
}

mgmt_backup() {
    title "بکاپ دستی دیتابیس"
    mkdir -p "$BACKUP_DIR"
    local out="$BACKUP_DIR/db_$(date +%Y-%m-%d_%H-%M-%S).sql"
    if mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$out" 2>/tmp/teamkan_backup.log; then
        ok "بکاپ ذخیره شد در: $out"
    else
        err "بکاپ شکست خورد. جزئیات: /tmp/teamkan_backup.log"
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
            *) warn "گزینه‌ی ناشناخته نادیده گرفته شد: $arg" ;;
        esac
    done

    if [[ $# -eq 0 ]]; then
        tty_read "مسیر یک بکاپ دیتابیس (.sql) برای بازیابی [اختیاری، برای رد شدن Enter بزن]: " RESTORE_DB_FILE
        tty_read "مسیر یک بکاپ سورس (.zip) برای بازیابی [اختیاری، برای رد شدن Enter بزن]: " RESTORE_SOURCE_FILE
    fi

    if [[ -z "$RESTORE_DB_FILE" && -z "$RESTORE_SOURCE_FILE" ]]; then
        warn "چیزی برای بازیابی نیست (نه --db دادی نه --source)."
        return
    fi

    validate_restore_file "$RESTORE_DB_FILE" "بکاپ دیتابیس" || return
    validate_restore_file "$RESTORE_SOURCE_FILE" "بکاپ سورس" || return

    title "در حال بازیابی بکاپ روی نصب موجود"
    warn "این کار فایل‌های فعلی رو بازنویسی می‌کنه (بازیابی سورس) و/یا ردیف‌های دیتابیس فعلی رو آپدیت/اضافه می‌کنه (بازیابی دیتابیس)."
    restore_source_backup
    restore_db_backup
    mgmt_restart_bot
    ok "بازیابی کامل شد."
}

mgmt_rewebhook() {
    title "در حال ریست کردن وب‌هوک"
    local token
    token="$(php -r "require '$WEBROOT/config.php'; echo BOT_TOKEN;")"
    local result
    result="$(curl -s "https://api.telegram.org/bot${token}/setWebhook?url=${SITE_URL}/bot.php")"
    echo "$result" | grep -q '"ok":true' && ok "وب‌هوک با موفقیت ریست شد." || warn "پاسخ تلگرام: $result"
}

mgmt_reset_panel_password() {
    title "در حال تغییر رمز پنل وب"
    tty_read "رمز جدید پنل رو وارد کن: " NEWPASS silent
    if [[ -z "$NEWPASS" ]]; then err "رمز نمی‌تونه خالی باشه."; return; fi
    local hash
    hash="$(NEWPASS="$NEWPASS" php -r "echo password_hash(getenv('NEWPASS'), PASSWORD_DEFAULT);")"
    mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
        -e "INSERT INTO settings (setting_key, setting_value) VALUES ('webpanel_password_hash', '$hash') ON DUPLICATE KEY UPDATE setting_value='$hash';" \
        && ok "رمز پنل با موفقیت تغییر کرد." \
        || err "آپدیت رمز شکست خورد."
}

mgmt_ssl_renew() {
    title "در حال تمدید/دریافت گواهی SSL"
    certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email \
        && ok "SSL تمدید/فعال شد." \
        || err "عملیات SSL شکست خورد. لاگ certbot رو چک کن (certbot certificates)."
}

# کاملاً خودکار: آخرین کد رو مستقیم از گیت‌هاب می‌کشه و دوباره دیپلوی می‌کنه.
# بدون سوال، پس چه محلی چه از طریق curl | sudo bash -s -- update یکسان کار می‌کنه.
mgmt_update_bot() {
    title "در حال آپدیت فایل‌های بات"
    fetch_source

    if [[ ! -f "$FETCHED_SRC_DIR/bot.php" ]]; then
        err "bot.php توی سورس دانلودشده پیدا نشد؛ آپدیت لغو شد."
        rm -rf "$FETCHED_SRC_DIR"
        return 1
    fi

    mkdir -p "$BACKUP_DIR"
    local backup_file="$BACKUP_DIR/webroot_before_update_$(date +%Y-%m-%d_%H-%M-%S).tar.gz"
    tar czf "$backup_file" -C "$(dirname "$WEBROOT")" "$(basename "$WEBROOT")" 2>/dev/null
    ok "فایل‌های فعلی بکاپ گرفته شدن در: $backup_file"

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

    mgmt_restart_bot
    ok "بات با موفقیت به آخرین نسخه‌ی شاخه‌ی '$REPO_BRANCH' آپدیت شد. config.php دست‌نخورده باقی موند."
}

# $1 ممکنه --yes باشه تا تأیید رو رد کنه (برای اجراهای curl|bash لازمه).
mgmt_uninstall() {
    local force="${1:-}"
    title "حذف کامل"
    warn "این کار مسیر فایل‌ها ($WEBROOT)، دیتابیس ($DB_NAME) و کانفیگ Nginx مربوط به $DOMAIN رو برای همیشه پاک می‌کنه."

    if [[ "$force" != "--yes" ]]; then
        tty_read "برای تأیید کلمه‌ی 'DELETE' رو تایپ کن: " CONFIRM
        if [[ "$CONFIRM" != "DELETE" ]]; then
            info "لغو شد."
            return
        fi
    fi

    rm -rf "$WEBROOT"
    rm -f "/etc/nginx/sites-enabled/$DOMAIN" "/etc/nginx/sites-available/$DOMAIN"
    systemctl reload nginx 2>/dev/null
    mysql -u root -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; DROP USER IF EXISTS '$DB_USER'@'localhost'; FLUSH PRIVILEGES;" 2>/dev/null
    rm -f /usr/local/bin/kanbot
    rm -rf "$CONF_DIR"
    ok "حذف کامل انجام شد."
    exit 0
}

show_menu() {
    load_conf
    while true; do
        echo
        echo -e "${C_BOLD}${C_BLUE}=============================================${C_RESET}"
        echo -e "${C_BOLD}${C_BLUE}   پنل مدیریت بات تیم کن   ($DOMAIN)${C_RESET}"
        echo -e "${C_BOLD}${C_BLUE}=============================================${C_RESET}"
        echo " 1) وضعیت سرویس‌ها"
        echo " 2) ری‌استارت بات (PHP-FPM + Nginx)"
        echo " 3) ری‌استارت همه‌ی سرویس‌ها (شامل MariaDB)"
        echo " 4) آپدیت بات (کشیدن آخرین نسخه از گیت‌هاب)"
        echo " 5) نمایش اطلاعات نصب"
        echo " 6) بکاپ دستی دیتابیس"
        echo " 7) بازیابی یک بکاپ قبلی (دیتابیس و/یا سورس)"
        echo " 8) ریست وب‌هوک تلگرام"
        echo " 9) تغییر رمز پنل"
        echo "10) تمدید/دریافت گواهی SSL"
        echo "11) حذف کامل"
        echo " 0) خروج"
        read -rp "یک گزینه انتخاب کن: " CH
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
            0) exit 0 ;;
            *) warn "گزینه‌ی نامعتبر." ;;
        esac
        pause
    done
}

# ==============================================================================
#  نقطه‌ی ورود
# ==============================================================================
main() {
    local cmd="${1:-auto}"
    [[ $# -gt 0 ]] && shift

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
            err "دستور ناشناخته: $cmd"
            usage
            exit 1
            ;;
    esac
}

main "$@"
