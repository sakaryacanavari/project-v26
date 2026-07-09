# Schema Sync Apply Order

Kapali beta kurulumu veya yeni ortama dagitim sirasinda SQL dosyalari asagidaki siraya gore uygulanmalidir.

## 1. Altyapi
1. [C:\laragon\www\schema_sync_auth_registration.sql](/C:/laragon/www/schema_sync_auth_registration.sql)
2. [C:\laragon\www\schema_sync_auth_rate_limits.sql](/C:/laragon/www/schema_sync_auth_rate_limits.sql)
3. [C:\laragon\www\schema_sync_auth_remember_tokens.sql](/C:/laragon/www/schema_sync_auth_remember_tokens.sql)
4. [C:\laragon\www\schema_sync_auth_password_resets.sql](/C:/laragon/www/schema_sync_auth_password_resets.sql)
5. [C:\laragon\www\schema_sync_cron_health.sql](/C:/laragon/www/schema_sync_cron_health.sql)
6. [C:\laragon\www\schema_sync_performance_indexes.sql](/C:/laragon/www/schema_sync_performance_indexes.sql)

## 2. Oyuncu ve Profil
7. [C:\laragon\www\schema_sync_user_language.sql](/C:/laragon/www/schema_sync_user_language.sql)
8. [C:\laragon\www\schema_sync_user_profile.sql](/C:/laragon/www/schema_sync_user_profile.sql)

## 3. Siyasi / Parti
9. [C:\laragon\www\schema_sync_party_logo_url.sql](/C:/laragon/www/schema_sync_party_logo_url.sql)
10. [C:\laragon\www\schema_sync_party_profile_extensions.sql](/C:/laragon/www/schema_sync_party_profile_extensions.sql)
11. [C:\laragon\www\schema_sync_party_daily_limits.sql](/C:/laragon/www/schema_sync_party_daily_limits.sql)
12. [C:\laragon\www\schema_sync_party_ad_cooldown.sql](/C:/laragon/www/schema_sync_party_ad_cooldown.sql)

## 4. Shout Sistemi
13. [C:\laragon\www\schema_sync_shouts.sql](/C:/laragon/www/schema_sync_shouts.sql)
14. [C:\laragon\www\schema_sync_shout_editing.sql](/C:/laragon/www/schema_sync_shout_editing.sql)
15. [C:\laragon\www\schema_sync_shout_features.sql](/C:/laragon/www/schema_sync_shout_features.sql)
16. [C:\laragon\www\schema_sync_shout_governance.sql](/C:/laragon/www/schema_sync_shout_governance.sql)
17. [C:\laragon\www\schema_sync_shout_moderation.sql](/C:/laragon/www/schema_sync_shout_moderation.sql)

## 5. Secimler
18. [C:\laragon\www\schema_sync_presidential_elections.sql](/C:/laragon/www/schema_sync_presidential_elections.sql)
19. [C:\laragon\www\schema_sync_election_calendar.sql](/C:/laragon/www/schema_sync_election_calendar.sql)

## 6. Genel Tamir Paketleri
20. [C:\laragon\www\schema_sync_phase12_repairs.sql](/C:/laragon/www/schema_sync_phase12_repairs.sql)
21. [C:\laragon\www\schema_sync_early_access.sql](/C:/laragon/www/schema_sync_early_access.sql)
22. [C:\laragon\www\schema_sync_early_access_repairs.sql](/C:/laragon/www/schema_sync_early_access_repairs.sql)

## Notlar
- Performans index dosyasi tekrar calistirilabilir olmali.
- `early_access_repairs` en sona birakilmali.
- Yeni ortamlarda SQL uygulama sonrasi admin ops ekrani ve smoke checklist birlikte dogrulanmali.
