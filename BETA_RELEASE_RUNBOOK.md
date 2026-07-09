# Kapali Beta Release Runbook

Bu belge kapali beta acilisindan once ve her buyuk deploy sonrasinda takip edilmelidir.

## 1. Hazirlik
- `php -l` ile son degisen controller ve route dosyalari kontrol edilir.
- [C:\laragon\www\SCHEMA_SYNC_APPLY_ORDER.md](/C:/laragon/www/SCHEMA_SYNC_APPLY_ORDER.md) icindeki siraya gore gerekli SQL dosyalari uygulanir.
- `tmp/logs/app.log` ve `tmp/logs/profile.log` yazilabilir olmali.
- `crons` altindaki cron dosyalari sunucuda planli gorev olarak bagli olmali.

## 2. Cron Sagligi
- `system_cron_status` tablosu kurulu olmali.
- [C:\laragon\www\crons\candidacyVotations.php](/C:/laragon/www/crons/candidacyVotations.php)
- [C:\laragon\www\crons\deleteOldChats.php](/C:/laragon/www/crons/deleteOldChats.php)
- [C:\laragon\www\crons\electionCalendar.php](/C:/laragon/www/crons/electionCalendar.php)
- [C:\laragon\www\crons\lawProposals.php](/C:/laragon/www/crons/lawProposals.php)
- [C:\laragon\www\crons\presidentialElections.php](/C:/laragon/www/crons/presidentialElections.php)

Admin ops ekraninda:
- geciken cron olmamali
- hata veren cron olmamali
- `beta blockers` alani temiz olmali

## 3. Smoke Test
- [C:\laragon\www\EARLY_ACCESS_SMOKE_CHECKLIST.md](/C:/laragon/www/EARLY_ACCESS_SMOKE_CHECKLIST.md) adimlari gercek hesaplarla kosulur.
- Mümkünse 2 oyuncu hesabi kullanilir:
  - normal vatandas
  - admin/test operatoru

## 4. Ekonomi ve Exploit Kontrolu
- market buy/sell cift tik ile negatif envanter uretmemeli
- ayni is ilanina iki hesap ayni anda girince tek kazanan olmali
- aktif iste sahip kullanici ikinci ise girememeli
- ayni sirket icin iki aktif bos is ilani acilamamali
- storage -> market satisinda miktar sifir altina dusmemeli

## 5. Moderasyon
- report edilen shout admin ops ekraninda gorunmeli
- rapor nedeni ve rapor sayisi gorunmeli
- gizle / geri ac / sustur aksiyonlari calismali

## 6. Acilis Gunu
- admin ops sayfasi acik tutulur
- ilk 24 saat `app.log` ve cron sagligi aktif izlenir
- ekonomi ve shout tarafinda anomali gorulurse yeni kayitlar gecici yavaslatilir

## 7. Acilisi Bloklayan Durumlar
- cron hatasi
- geciken cron
- market buy/sell cift islem bugi
- is ilaninda cift atama
- login/signup fatal hatasi
- settings/language kaydetme hatasi
- ana sayfa / shout feed fatal hatasi
