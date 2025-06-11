if [ "$EUID" -ne 0 ]; then
    alias artisan="php artisan"
    alias tinker="php artisan tinker"
    alias queue="php artisan queue:work"
    alias logs="tail -f storage/logs/laravel.log"
fi
