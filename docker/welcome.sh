#!/bin/bash

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
PURPLE='\033[0;35m'
CYAN='\033[0;36m'
WHITE='\033[1;37m'
NC='\033[0m' # No Color

# Function to center text
center_text() {
    local text="$1"
    local width=${2:-80}
    local padding=$(( (width - ${#text}) / 2 ))
    printf "%*s%s\n" $padding "" "$text"
}

print_line() {
    printf "${BLUE}%*s${NC}\n" 80 | tr ' ' '='
}

get_system_info() {
    local php_version=$(php -v | head -n1 | cut -d' ' -f2)
    local laravel_version=$(cd /var/www/html && php artisan --version 2>/dev/null | cut -d' ' -f3 || echo "Loading...")
    local user=$(whoami)
    local disk_usage=$(df -h /var/www/html | awk 'NR==2 {print $5}')
    
    echo -e "${GREEN}System Information:${NC}"
    echo -e " ${CYAN}PHP Version:${NC} $php_version"
    echo -e " ${CYAN}Laravel Version:${NC} $laravel_version"
    echo -e " ${CYAN}User:${NC} $user"
    echo -e " ${CYAN}Disk Usage:${NC} $disk_usage"
}

check_services() {
    echo -e "\n${GREEN}Service Status:${NC}"

    if mysql -h mysql -u depthnet -psecret -D depthnet -e 'SELECT 1' > /dev/null 2>&1; then
        echo -e " ${GREEN}MySQL:${NC} Connected"
    else
        echo -e " ${RED}MySQL:${NC} Not available"
    fi

    if cd /var/www/html && php artisan about > /dev/null 2>&1; then
        echo -e " ${GREEN}Laravel:${NC} Ready"
    else
        echo -e " ${YELLOW}Laravel:${NC} Initializing..."
    fi

    if [ -d "/var/www/html/node_modules" ]; then
        echo -e " ${GREEN}Node.js:${NC} Dependencies installed"
    else
        echo -e " ${YELLOW}Node.js:${NC} Dependencies missing"
    fi
}

show_welcome() {
    clear

    print_line
    echo -e "${PURPLE}"
    echo "██████╗ ███████╗██████╗ ████████╗██╗  ██╗███╗   ██╗███████╗████████╗"
    echo "██╔══██╗██╔════╝██╔══██╗╚══██╔══╝██║  ██║████╗  ██║██╔════╝╚══██╔══╝"
    echo "██║  ██║█████╗  ██████╔╝   ██║   ███████║██╔██╗ ██║█████╗     ██║   "
    echo "██║  ██║██╔══╝  ██╔═══╝    ██║   ██╔══██║██║╚██╗██║██╔══╝     ██║   "
    echo "██████╔╝███████╗██║        ██║   ██║  ██║██║ ╚████║███████╗   ██║   "
    echo "╚═════╝ ╚══════╝╚═╝        ╚═╝   ╚═╝  ╚═╝╚═╝  ╚═══╝╚══════╝   ╚═╝   "
    echo -e "${NC}"

    echo -e "${CYAN}"
    center_text "Autonomous AI Agent Platform"
    echo -e "${NC}"
    print_line

    get_system_info
    check_services

    if [ "$EUID" -ne 0 ]; then
        echo -e "\n${GREEN}Quick Commands:${NC}"
        echo -e "  ${YELLOW}artisan${NC}           - Laravel Artisan CLI"
        echo -e "  ${YELLOW}tinker${NC}            - Laravel REPL"
        echo -e "  ${YELLOW}queue${NC}             - Start queue worker"
        echo -e "  ${YELLOW}logs${NC}              - View Laravel logs"
        echo -e "  ${YELLOW}composer test${NC}     - Run tests"
        echo -e "  ${YELLOW}composer dev${NC}      - Start development servers"
    fi

    print_line
    echo -e "${WHITE}Welcome to DepthNet Development Environment!${NC}"
    echo -e "${CYAN}Type 'help' for more commands or 'exit' to leave the container.${NC}"
    print_line
}

show_welcome
