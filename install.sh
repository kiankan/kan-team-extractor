#!/usr/bin/env bash
# ==============================================================================
#  install.sh — Remote installer and management CLI for the Team Kan bot.
#
#  Designed to be run either:
#    A) Remotely, straight from GitHub (no local files needed):
#         curl -sL https://raw.githubusercontent.com/kiankan/kan-team-extractor/main/install.sh \
#           | sudo bash -s -- install --domain=bot.example.com --token=BOT_TOKEN --admin=ADMIN_ID
#
#    B) Locally, after it has been installed once (installed as /usr/local/bin/kanbot):
#         sudo kanbot                Open the interactive management menu
#         sudo kanbot menu           Same as above
#         sudo kanbot update         Pull the latest code from GitHub and redeploy
#         sudo kanbot info           Show install info (domain, panel URL, DB, ...)
#         sudo kanbot status         Show service status
#         sudo kanbot restart        Restart all related services
#         sudo kanbot uninstall      Full removal (asks for confirmation)
#
#  Since installs launched via `curl | sudo bash` have no real stdin to read
#  answers from, `install` and `uninstall` accept flags instead of prompts
#  when there's no interactive terminal attached. See usage() below.
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
# SELF_PATH may not resolve to a real file when run via curl|bash — never rely
# on it for anything critical, only as a best-effort fallback.
SELF_PATH="$(readlink -f "${BASH_SOURCE[0]}" 2>/dev/null || true)"

require_root() {
    if [[ $EUID -ne 0 ]]; then
        err "This script must be run as root. Example: sudo bash install.sh"
        exit 1
    fi
}

require_apt() {
    if ! command -v apt-get >/dev/null 2>&1; then
        err "This installer only supports Debian/Ubuntu-based distros (apt)."
        exit 1
    fi
}

pause() { read -rp "Press Enter to continue..." _ < /dev/tty 2>/dev/null || read -rp "Press Enter to continue..." _; }

rand_pass() { openssl rand -hex 16; }

# Reads a line of interactive input even when the script itself was piped in
# via `curl | sudo bash` (stdin in that case is the script source, not the
# keyboard). /dev/tty is still the real terminal as long as one is attached,
# so we read from there instead. Usage: tty_read "Prompt: " VARNAME [silent]
tty_read() {
    local __prompt="$1" __var="$2" __silent="${3:-}"
    if [[ ! -r /dev/tty ]]; then
        err "No interactive terminal available to read input for: $__prompt"
        err "Re-run with the appropriate flags instead (see: install.sh --help)."
        exit 1
    fi
    if [[ "$__silent" == "silent" ]]; then
        read -rsp "$__prompt" "$__var" < /dev/tty; echo
    else
        read -rp "$__prompt" "$__var" < /dev/tty
    fi
}

usage() {
    cat <<USAGE
Usage:
  sudo bash install.sh install                          Interactive install (asks questions)
  sudo bash install.sh install [--domain=D] [--token=T] [--admin=A] [--email=E]
                                                          Unattended install (no questions asked)
  sudo bash install.sh update
  sudo bash install.sh info
  sudo bash install.sh status
  sudo bash install.sh restart
  sudo bash install.sh uninstall [--yes]
  sudo bash install.sh menu

Remote one-liner (no local files needed) — interactive, asks questions
one by one just like a local run:
  curl -sL $INSTALL_SCRIPT_URL | sudo bash -s -- install

Remote one-liner, fully unattended (all answers given up front as flags):
  curl -sL $INSTALL_SCRIPT_URL | sudo bash -s -- install \\
      --domain=bot.example.com --token=BOT_TOKEN --admin=ADMIN_ID [--email=you@example.com]

  curl -sL $INSTALL_SCRIPT_URL | sudo bash -s -- update
  curl -sL $INSTALL_SCRIPT_URL | sudo bash -s -- info
  curl -sL $INSTALL_SCRIPT_URL | sudo bash -s -- restart
  curl -sL $INSTALL_SCRIPT_URL | sudo bash -s -- uninstall --yes
USAGE
}

# ==============================================================================
#  Fetching source from GitHub (used by both install and update)
# ==============================================================================

# Downloads the project source into a fresh temp dir and sets FETCHED_SRC_DIR.
fetch_source() {
    title "Downloading source from GitHub ($REPO_URL, branch: $REPO_BRANCH)"
    local tmp_dir
    tmp_dir="$(mktemp -d)"

    if command -v git >/dev/null 2>&1 \
        && git clone --depth 1 --branch "$REPO_BRANCH" "${REPO_URL}.git" "$tmp_dir" >/tmp/teamkan_clone.log 2>&1; then
        ok "Source cloned via git."
    else
        warn "git clone unavailable or failed; falling back to tarball download."
        rm -rf "$tmp_dir"; tmp_dir="$(mktemp -d)"
        local tarball_url="${REPO_URL}/archive/refs/heads/${REPO_BRANCH}.tar.gz"
        local tarball_file="/tmp/teamkan_src_$$.tar.gz"
        curl -fsSL "$tarball_url" -o "$tarball_file" 2>/tmp/teamkan_dl.log \
            || { err "Failed to download source from $tarball_url (details: /tmp/teamkan_dl.log)"; exit 1; }
        tar xzf "$tarball_file" -C "$tmp_dir" --strip-components=1 \
            || { err "Failed to extract source archive."; exit 1; }
        rm -f "$tarball_file"
        ok "Source downloaded via tarball."
    fi
    FETCHED_SRC_DIR="$tmp_dir"
}

# ==============================================================================
#  Install section
# ==============================================================================

collect_inputs() {
    title "Collecting install information"
    tty_read "Domain already pointed at this server (e.g. bot.example.com): " DOMAIN
    while [[ -z "$DOMAIN" ]]; do tty_read "Domain cannot be empty. Enter it again: " DOMAIN; done

    tty_read "Bot token (from @BotFather): " BOT_TOKEN
    while [[ -z "$BOT_TOKEN" ]]; do tty_read "Token cannot be empty. Enter it again: " BOT_TOKEN; done

    tty_read "Numeric ID of the main admin (from @userinfobot): " ADMIN_ID
    while ! [[ "$ADMIN_ID" =~ ^[0-9]+$ ]]; do tty_read "Must be numeric only. Enter it again: " ADMIN_ID; done

    tty_read "Your email for the SSL certificate (Let's Encrypt) [optional, Enter to skip]: " SSL_EMAIL

    finalize_install_vars
    echo
    info "Summary:"
    echo "  Domain:      $DOMAIN"
    echo "  Web root:    $WEBROOT"
    echo "  DB name:     $DB_NAME"
    tty_read "Does everything look right? Continue? [Y/n]: " CONFIRM
    if [[ "$CONFIRM" =~ ^[Nn]$ ]]; then
        err "Installation cancelled."
        exit 1
    fi
}

# Non-interactive input path: parses --domain= --token= --admin= --email=
parse_install_args() {
    DOMAIN=""; BOT_TOKEN=""; ADMIN_ID=""; SSL_EMAIL=""
    for arg in "$@"; do
        case "$arg" in
            --domain=*) DOMAIN="${arg#*=}" ;;
            --token=*)  BOT_TOKEN="${arg#*=}" ;;
            --admin=*)  ADMIN_ID="${arg#*=}" ;;
            --email=*)  SSL_EMAIL="${arg#*=}" ;;
            *) warn "Unknown option ignored: $arg" ;;
        esac
    done

    local missing=()
    [[ -z "$DOMAIN" ]]    && missing+=("--domain")
    [[ -z "$BOT_TOKEN" ]] && missing+=("--token")
    [[ -z "$ADMIN_ID" ]]  && missing+=("--admin")
    if [[ ${#missing[@]} -gt 0 ]]; then
        err "Missing required options: ${missing[*]}"
        usage
        exit 1
    fi
    if ! [[ "$ADMIN_ID" =~ ^[0-9]+$ ]]; then
        err "--admin must be numeric only."
        exit 1
    fi

    finalize_install_vars
    info "Installing with: domain=$DOMAIN admin=$ADMIN_ID email=${SSL_EMAIL:-<none>}"
}

finalize_install_vars() {
    DB_NAME="teamkanbot"
    DB_USER="teamkanbot"
    DB_PASS="$(rand_pass)"
    WEBROOT="/var/www/$DOMAIN"
}

install_packages() {
    title "Installing prerequisites (nginx, PHP-FPM, MariaDB, ...)"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq nginx mariadb-server curl git unzip zip cron \
        php-fpm php-mysql php-curl php-mbstring php-xml php-zip php-cli \
        certbot python3-certbot-nginx >/tmp/teamkan_apt.log 2>&1 \
        || { err "Package installation failed. Details: /tmp/teamkan_apt.log"; exit 1; }
    ok "Prerequisites installed."
}

detect_php_fpm() {
    PHP_FPM_SERVICE="$(systemctl list-units --type=service --all 2>/dev/null \
        | grep -oE 'php[0-9.]*-fpm\.service' | head -n1)"
    if [[ -z "$PHP_FPM_SERVICE" ]]; then
        err "php-fpm service not found."
        exit 1
    fi
    systemctl enable --now "$PHP_FPM_SERVICE" >/dev/null 2>&1

    for i in 1 2 3 4 5; do
        PHP_FPM_SOCK="$(find /run/php -name 'php*-fpm.sock' 2>/dev/null | head -n1)"
        [[ -n "$PHP_FPM_SOCK" ]] && break
        sleep 1
    done
    if [[ -z "$PHP_FPM_SOCK" ]]; then
        err "php-fpm socket not found."
        exit 1
    fi
}

setup_database() {
    title "Creating the database"
    systemctl enable --now mariadb >/dev/null 2>&1
    mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
    if [[ $? -ne 0 ]]; then
        err "Database creation failed. Is MariaDB installed correctly?"
        exit 1
    fi
    ok "Database '$DB_NAME' and user '$DB_USER' created."
}

# Copies the fetched GitHub source ($FETCHED_SRC_DIR) into $WEBROOT.
deploy_files() {
    title "Copying project files to $WEBROOT"
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

    cat > "$WEBROOT/config.php" <<PHP
<?php
declare(strict_types=1);

define('BOT_TOKEN', '$BOT_TOKEN');
define('ADMIN_ID', $ADMIN_ID);

define('DB_HOST', 'localhost');
define('DB_NAME', '$DB_NAME');
define('DB_USER', '$DB_USER');
define('DB_PASS', '$DB_PASS');
PHP

    chown -R www-data:www-data "$WEBROOT"
    find "$WEBROOT" -type d -exec chmod 750 {} \;
    find "$WEBROOT" -type f -exec chmod 640 {} \;
    ok "Files copied and config.php created."
}

create_tables() {
    title "Creating database tables"
    php "$WEBROOT/table.php" >/tmp/teamkan_tables.log 2>&1
    if grep -qi "error\|خطا" /tmp/teamkan_tables.log; then
        err "Failed to create tables. Details: /tmp/teamkan_tables.log"
        exit 1
    fi
    ok "Database tables created."
}

setup_nginx() {
    title "Configuring Nginx"
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
    }

    location ~ /\. { deny all; }
}
NGINX
    ln -sf "/etc/nginx/sites-available/$DOMAIN" "/etc/nginx/sites-enabled/$DOMAIN"
    if ! nginx -t >/tmp/teamkan_nginx.log 2>&1; then
        err "Invalid Nginx configuration. Details: /tmp/teamkan_nginx.log"
        exit 1
    fi
    systemctl reload nginx
    ok "Nginx configured (HTTP)."
}

setup_ssl() {
    title "Getting a free SSL certificate (Let's Encrypt)"
    warn "For this step to succeed, $DOMAIN must already be pointed at this server's IP."
    local email_arg=(--register-unsafely-without-email)
    [[ -n "${SSL_EMAIL:-}" ]] && email_arg=(-m "$SSL_EMAIL")

    if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos "${email_arg[@]}" >/tmp/teamkan_certbot.log 2>&1; then
        ok "SSL certificate obtained and enabled successfully."
        SITE_URL="https://$DOMAIN"
    else
        warn "Getting SSL failed (details: /tmp/teamkan_certbot.log). Continuing on HTTP for now;"
        warn "once the domain's DNS is set up correctly, run 'kanbot' -> option to renew SSL, or 'sudo kanbot menu'."
        warn "Note: Telegram only accepts HTTPS for webhooks, so the bot won't be reachable until SSL is set up."
        SITE_URL="http://$DOMAIN"
    fi
}

set_webhook() {
    title "Setting the Telegram webhook"
    local result
    result="$(curl -s "https://api.telegram.org/bot${BOT_TOKEN}/setWebhook?url=${SITE_URL}/bot.php")"
    if echo "$result" | grep -q '"ok":true'; then
        ok "Webhook successfully set to ${SITE_URL}/bot.php"
    else
        warn "Setting the webhook failed. Telegram's response: $result"
    fi
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

# Saves a persistent copy of this management script so 'kanbot' keeps working
# later, regardless of whether the original install ran from a local file or
# straight off a curl|bash pipe (where there is no on-disk script to symlink).
install_management_symlink() {
    mkdir -p "$CONF_DIR"
    if curl -fsSL "$INSTALL_SCRIPT_URL" -o "$PERSIST_SCRIPT_PATH" 2>/tmp/teamkan_selfdl.log; then
        chmod +x "$PERSIST_SCRIPT_PATH"
    elif [[ -n "$SELF_PATH" && -f "$SELF_PATH" ]]; then
        cp "$SELF_PATH" "$PERSIST_SCRIPT_PATH"
        chmod +x "$PERSIST_SCRIPT_PATH"
    else
        warn "Could not save a persistent copy of the management script; 'kanbot' command will not be available."
        return
    fi
    ln -sf "$PERSIST_SCRIPT_PATH" /usr/local/bin/kanbot
    ok "Management command installed: run 'sudo kanbot' anytime to open the menu."
}

do_install_steps() {
    install_packages
    setup_database
    fetch_source
    deploy_files
    create_tables
    setup_nginx
    setup_ssl
    set_webhook
    save_conf
    install_management_symlink
    [[ -n "${FETCHED_SRC_DIR:-}" ]] && rm -rf "$FETCHED_SRC_DIR"

    title "Installation complete 🎉"
    echo -e "${C_GREEN}Web panel URL:${C_RESET} ${SITE_URL}/webpanel.php"
    echo -e "${C_GREEN}Default panel password:${C_RESET} admin  ${C_YELLOW}(change it right now from the 'Security' tab)${C_RESET}"
    echo -e "${C_GREEN}Managing it later:${C_RESET} run ${C_BOLD}sudo kanbot${C_RESET} to open the management menu,"
    echo -e "            or ${C_BOLD}sudo kanbot update|info|status|restart|uninstall${C_RESET} directly."
    echo -e "${C_GREEN}Install info saved to:${C_RESET} $CONF_FILE"
}

run_install() {
    require_root
    require_apt
    if [[ $# -eq 0 ]]; then
        # No flags given: ask questions interactively. This reads from
        # /dev/tty, so it works fine even when launched via curl | sudo bash.
        collect_inputs
    else
        parse_install_args "$@"
    fi
    do_install_steps
}

# ==============================================================================
#  Management section (runs if the bot has already been installed)
# ==============================================================================

load_conf() {
    if [[ ! -f "$CONF_FILE" ]]; then
        err "Install config file not found ($CONF_FILE). You need to run the installer first."
        exit 1
    fi
    # shellcheck disable=SC1090
    source "$CONF_FILE"
}

find_phpfpm_service() {
    systemctl list-units --type=service --all 2>/dev/null | grep -oE 'php[0-9.]*-fpm\.service' | head -n1
}

mgmt_status() {
    title "Service status"
    for s in nginx mariadb; do
        systemctl is-active --quiet "$s" && ok "$s: active" || err "$s: inactive"
    done
    local phpfpm
    phpfpm="$(find_phpfpm_service)"
    [[ -n "$phpfpm" ]] && { systemctl is-active --quiet "$phpfpm" && ok "$phpfpm: active" || err "$phpfpm: inactive"; }
}

mgmt_restart_bot() {
    title "Restarting the bot (PHP-FPM + Nginx)"
    local phpfpm
    phpfpm="$(find_phpfpm_service)"
    [[ -n "$phpfpm" ]] && systemctl restart "$phpfpm" && ok "$phpfpm restarted."
    systemctl restart nginx && ok "nginx restarted."
}

mgmt_restart_all() {
    title "Restarting all services"
    mgmt_restart_bot
    systemctl restart mariadb && ok "mariadb restarted."
}

mgmt_info() {
    title "Install information"
    echo "Domain:       $DOMAIN"
    echo "Panel URL:    ${SITE_URL}/webpanel.php"
    echo "Web root:     $WEBROOT"
    echo "DB name:      $DB_NAME"
    echo "DB user:      $DB_USER"
}

mgmt_backup() {
    title "Manual database backup"
    mkdir -p "$BACKUP_DIR"
    local out="$BACKUP_DIR/db_$(date +%Y-%m-%d_%H-%M-%S).sql"
    if mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" > "$out" 2>/tmp/teamkan_backup.log; then
        ok "Backup saved to: $out"
    else
        err "Backup failed. Details: /tmp/teamkan_backup.log"
    fi
}

mgmt_rewebhook() {
    title "Resetting the webhook"
    local token
    token="$(php -r "require '$WEBROOT/config.php'; echo BOT_TOKEN;")"
    local result
    result="$(curl -s "https://api.telegram.org/bot${token}/setWebhook?url=${SITE_URL}/bot.php")"
    echo "$result" | grep -q '"ok":true' && ok "Webhook reset successfully." || warn "Telegram's response: $result"
}

mgmt_reset_panel_password() {
    title "Resetting the web panel password"
    tty_read "Enter the new panel password: " NEWPASS silent
    if [[ -z "$NEWPASS" ]]; then err "Password cannot be empty."; return; fi
    local hash
    hash="$(php -r "echo password_hash('$NEWPASS', PASSWORD_DEFAULT);")"
    mysql -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
        -e "INSERT INTO settings (setting_key, setting_value) VALUES ('webpanel_password_hash', '$hash') ON DUPLICATE KEY UPDATE setting_value='$hash';" \
        && ok "Panel password changed successfully." \
        || err "Failed to update the password."
}

mgmt_ssl_renew() {
    title "Renewing / obtaining the SSL certificate"
    certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email \
        && ok "SSL renewed/enabled." \
        || err "SSL operation failed. Check the certbot log (certbot certificates)."
}

# Fully automated: pulls the latest code straight from GitHub and redeploys.
# No prompts, so this works the same locally or via curl | sudo bash -s -- update.
mgmt_update_bot() {
    title "Update bot files"
    fetch_source

    if [[ ! -f "$FETCHED_SRC_DIR/bot.php" ]]; then
        err "bot.php was not found in the downloaded source; aborting update."
        rm -rf "$FETCHED_SRC_DIR"
        return 1
    fi

    mkdir -p "$BACKUP_DIR"
    local backup_file="$BACKUP_DIR/webroot_before_update_$(date +%Y-%m-%d_%H-%M-%S).tar.gz"
    tar czf "$backup_file" -C "$(dirname "$WEBROOT")" "$(basename "$WEBROOT")" 2>/dev/null
    ok "Current files backed up to: $backup_file"

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
    ok "Bot updated successfully to the latest version on '$REPO_BRANCH'. config.php was left untouched."
}

# $1 may be --yes to skip the confirmation prompt (needed for curl|bash runs).
mgmt_uninstall() {
    local force="${1:-}"
    title "Full uninstall"
    warn "This will permanently delete the web root ($WEBROOT), the database ($DB_NAME), and the Nginx config for $DOMAIN."

    if [[ "$force" != "--yes" ]]; then
        tty_read "Type 'DELETE' to confirm: " CONFIRM
        if [[ "$CONFIRM" != "DELETE" ]]; then
            info "Cancelled."
            return
        fi
    fi

    rm -rf "$WEBROOT"
    rm -f "/etc/nginx/sites-enabled/$DOMAIN" "/etc/nginx/sites-available/$DOMAIN"
    systemctl reload nginx 2>/dev/null
    mysql -u root -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; DROP USER IF EXISTS '$DB_USER'@'localhost'; FLUSH PRIVILEGES;" 2>/dev/null
    rm -f /usr/local/bin/kanbot
    rm -rf "$CONF_DIR"
    ok "Full uninstall complete."
    exit 0
}

show_menu() {
    load_conf
    while true; do
        echo
        echo -e "${C_BOLD}${C_BLUE}=============================================${C_RESET}"
        echo -e "${C_BOLD}${C_BLUE}   Team Kan Bot Management Panel  ($DOMAIN)${C_RESET}"
        echo -e "${C_BOLD}${C_BLUE}=============================================${C_RESET}"
        echo " 1) Service status"
        echo " 2) Restart bot (PHP-FPM + Nginx)"
        echo " 3) Restart all services (incl. MariaDB)"
        echo " 4) Update bot (pull latest from GitHub)"
        echo " 5) Show install info"
        echo " 6) Manual database backup"
        echo " 7) Reset Telegram webhook"
        echo " 8) Reset panel password"
        echo " 9) Renew/get SSL certificate"
        echo "10) Full uninstall"
        echo " 0) Exit"
        read -rp "Choose an option: " CH
        case "$CH" in
            1) mgmt_status ;;
            2) mgmt_restart_bot ;;
            3) mgmt_restart_all ;;
            4) mgmt_update_bot ;;
            5) mgmt_info ;;
            6) mgmt_backup ;;
            7) mgmt_rewebhook ;;
            8) mgmt_reset_panel_password ;;
            9) mgmt_ssl_renew ;;
            10) mgmt_uninstall ;;
            0) exit 0 ;;
            *) warn "Invalid option." ;;
        esac
        pause
    done
}

# ==============================================================================
#  Entry point
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
            err "Unknown command: $cmd"
            usage
            exit 1
            ;;
    esac
}

main "$@"
