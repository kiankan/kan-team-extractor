#!/usr/bin/env bash
# ==============================================================================
#  install.sh — Installer and management tool for the Team Kan bot on a
#  dedicated server / VPS (Ubuntu/Debian). This script performs a full install
#  (nginx + PHP-FPM + MariaDB + SSL + webhook) and, after installation, also
#  works as a command-line management panel.
#
#  Usage:
#    sudo bash install.sh            Install (first run) or open the management menu (later runs)
#    sudo bash install.sh install    Force running the install flow from scratch
#    sudo bash install.sh menu       Open the management menu directly
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

CONF_DIR="/etc/teamkan-bot"
CONF_FILE="$CONF_DIR/install.conf"
BACKUP_DIR="/root/teamkan-backups"
SELF_PATH="$(readlink -f "${BASH_SOURCE[0]}")"
SRC_DIR="$(cd "$(dirname "$SELF_PATH")" && pwd)"

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

pause() { read -rp "Press Enter to continue..." _; }

rand_pass() { openssl rand -hex 16; }

# ==============================================================================
#  Install section
# ==============================================================================

collect_inputs() {
    title "Collecting install information"
    read -rp "Domain already pointed at this server (e.g. bot.example.com): " DOMAIN
    while [[ -z "$DOMAIN" ]]; do read -rp "Domain cannot be empty. Enter it again: " DOMAIN; done

    read -rp "Bot token (from @BotFather): " BOT_TOKEN
    while [[ -z "$BOT_TOKEN" ]]; do read -rp "Token cannot be empty. Enter it again: " BOT_TOKEN; done

    read -rp "Numeric ID of the main admin (from @userinfobot): " ADMIN_ID
    while ! [[ "$ADMIN_ID" =~ ^[0-9]+$ ]]; do read -rp "Must be numeric only. Enter it again: " ADMIN_ID; done

    read -rp "Your email for the SSL certificate (Let's Encrypt) [optional, Enter to skip]: " SSL_EMAIL

    DB_NAME="teamkanbot"
    DB_USER="teamkanbot"
    DB_PASS="$(rand_pass)"
    WEBROOT="/var/www/$DOMAIN"

    echo
    info "Summary:"
    echo "  Domain:      $DOMAIN"
    echo "  Web root:    $WEBROOT"
    echo "  DB name:     $DB_NAME"
    read -rp "Does everything look right? Continue? [Y/n]: " CONFIRM
    if [[ "$CONFIRM" =~ ^[Nn]$ ]]; then
        err "Installation cancelled."
        exit 1
    fi
}

install_packages() {
    title "Installing prerequisites (nginx, PHP-FPM, MariaDB, ...)"
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y -qq nginx mariadb-server curl unzip zip cron \
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

deploy_files() {
    title "Copying project files to $WEBROOT"
    mkdir -p "$WEBROOT"
    # Copy every file from this repo except install.sh itself
    shopt -s dotglob nullglob
    for item in "$SRC_DIR"/*; do
        base="$(basename "$item")"
        [[ "$base" == "install.sh" ]] && continue
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
        warn "once the domain's DNS is set up correctly, use the 'Renew/get SSL certificate' option from the management menu again."
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

install_management_symlink() {
    ln -sf "$SELF_PATH" /usr/local/bin/kanbot 2>/dev/null
    chmod +x "$SELF_PATH"
}

run_install() {
    require_root
    require_apt
    collect_inputs
    install_packages
    setup_database
    deploy_files
    create_tables
    setup_nginx
    setup_ssl
    set_webhook
    save_conf
    install_management_symlink

    title "Installation complete 🎉"
    echo -e "${C_GREEN}Web panel URL:${C_RESET} ${SITE_URL}/webpanel.php"
    echo -e "${C_GREEN}Default panel password:${C_RESET} admin  ${C_YELLOW}(change it right now from the 'Security' tab)${C_RESET}"
    echo -e "${C_GREEN}Managing it later:${C_RESET} from now on, run ${C_BOLD}kanbot${C_RESET} (or re-run this same script) to open the management menu."
    echo -e "${C_GREEN}Install info saved to:${C_RESET} $CONF_FILE"
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
    read -rsp "Enter the new panel password: " NEWPASS; echo
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

mgmt_update_bot() {
    title "Update bot files"
    info "Upload the new source to this server first — either a plain folder (e.g. via scp -r)"
    info "or a .zip / .tar.gz archive — then enter its path below. config.php will NOT be touched."
    read -rp "Path to the new source (folder or archive): " SRC_PATH

    if [[ ! -e "$SRC_PATH" ]]; then
        err "Path not found: $SRC_PATH"
        return
    fi

    local tmp_dir=""
    local new_src=""

    if [[ -d "$SRC_PATH" ]]; then
        # مسیر یک پوشه‌ی معمولیه؛ مستقیم از همون استفاده می‌کنیم
        new_src="$SRC_PATH"
    else
        tmp_dir="$(mktemp -d)"
        case "$SRC_PATH" in
            *.zip)
                command -v unzip >/dev/null 2>&1 || apt-get install -y -qq unzip >/dev/null 2>&1
                unzip -oq "$SRC_PATH" -d "$tmp_dir" || { err "Failed to extract the zip file."; rm -rf "$tmp_dir"; return; }
                ;;
            *.tar.gz|*.tgz)
                tar xzf "$SRC_PATH" -C "$tmp_dir" || { err "Failed to extract the tar.gz file."; rm -rf "$tmp_dir"; return; }
                ;;
            *)
                err "Unsupported path: must be an existing folder, or a .zip / .tar.gz file."
                rm -rf "$tmp_dir"
                return
                ;;
        esac
        new_src="$tmp_dir"

        # If the archive has a single top-level folder, step into it
        local entries=()
        while IFS= read -r -d '' e; do entries+=("$e"); done < <(find "$new_src" -mindepth 1 -maxdepth 1 -print0)
        if [[ ${#entries[@]} -eq 1 && -d "${entries[0]}" ]]; then
            new_src="${entries[0]}"
        fi
    fi

    if [[ ! -f "$new_src/bot.php" ]]; then
        err "bot.php was not found in '$new_src'; this doesn't look like a valid project update."
        [[ -n "$tmp_dir" ]] && rm -rf "$tmp_dir"
        return
    fi

    # Back up the current web root before overwriting anything
    mkdir -p "$BACKUP_DIR"
    local backup_file="$BACKUP_DIR/webroot_before_update_$(date +%Y-%m-%d_%H-%M-%S).tar.gz"
    tar czf "$backup_file" -C "$(dirname "$WEBROOT")" "$(basename "$WEBROOT")" 2>/dev/null
    ok "Current files backed up to: $backup_file"

    # Copy the new files over, but never touch config.php or install.sh
    shopt -s dotglob nullglob
    for item in "$new_src"/*; do
        base="$(basename "$item")"
        [[ "$base" == "config.php" ]] && continue
        [[ "$base" == "install.sh" ]] && continue
        cp -rf "$item" "$WEBROOT/"
    done
    shopt -u dotglob nullglob

    chown -R www-data:www-data "$WEBROOT"
    find "$WEBROOT" -type d -exec chmod 750 {} \;
    find "$WEBROOT" -type f -exec chmod 640 {} \;

    # Apply any new/changed database tables
    php "$WEBROOT/table.php" >/tmp/teamkan_tables.log 2>&1

    [[ -n "$tmp_dir" ]] && rm -rf "$tmp_dir"

    mgmt_restart_bot
    ok "Bot updated successfully. config.php was left untouched."
}

mgmt_uninstall() {
    title "Full uninstall"
    warn "This will permanently delete the web root ($WEBROOT), the database ($DB_NAME), and the Nginx config for $DOMAIN."
    read -rp "Type 'DELETE' to confirm: " CONFIRM
    if [[ "$CONFIRM" != "DELETE" ]]; then
        info "Cancelled."
        return
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
        echo " 4) Update bot (deploy new code)"
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
    local mode="${1:-auto}"
    case "$mode" in
        install) require_root; require_apt; run_install ;;
        menu)    require_root; show_menu ;;
        auto)
            require_root
            if [[ -f "$CONF_FILE" ]]; then
                show_menu
            else
                require_apt
                run_install
            fi
            ;;
        *)
            echo "Usage: $0 [install|menu]"
            exit 1
            ;;
    esac
}

main "${1:-}"
