# Format Sonrasi Kurulum

Bu proje su an Laragon + PHP + MySQL + Composer yapisinda calisiyor. Format sonrasi en az kayipla geri donmek icin asagidaki sira izlenmeli.

## 1. Format Oncesi Mutlaka Yedekle

### Kritik dosyalar
- `C:\laragon\www\conf.php`
- `C:\laragon\www\composer.json`
- `C:\laragon\www\composer.lock`
- `C:\laragon\www\package.json`
- `C:\laragon\www\package-lock.json`
- `C:\laragon\www\db.sql`
- `C:\laragon\www\SCHEMA_SYNC_APPLY_ORDER.md`
- `C:\laragon\www\BETA_RELEASE_RUNBOOK.md`
- `C:\laragon\www\BETA_FINAL_SMOKE_SCENARIOS.md`
- `C:\laragon\www\BETA_QUICK_TEST_FLOW.md`
- `C:\laragon\www\BETA_DAY0_MONITORING.md`
- `C:\laragon\www\scripts\beta_health_check.php`

### Uygulama klasorleri
- `C:\laragon\www\App`
- `C:\laragon\www\templates`
- `C:\laragon\www\lang`
- `C:\laragon\www\htdocs`
- `C:\laragon\www\public`
- `C:\laragon\www\crons`

### Veritabani
- Canli veritabani dump'i alin.
- Sadece `db.sql` taban schema icin yeterli olabilir ama canli oyundaki son durum icin tek basina yeterli degildir.

### Laragon ve sistem bilgisi
- PHP surumu: `8.0.30`
- Gerekli PHP extensionlari:
  - `curl`
  - `dom`
  - `fileinfo`
  - `mbstring`
  - `mysqli`
  - `mysqlnd`
  - `openssl`
  - `pdo_mysql`
  - `session`
  - `simplexml`
  - `xml`
  - `zip`
- Laragon icindeki:
  - aktif PHP surumu
  - aktif MySQL surumu
  - varsa custom Apache/Nginx ayarlari
  - `C:\Windows\System32\drivers\etc\hosts` icindeki ozel domain kayitlari

## 2. Bu Projede Ozellikle Dikkat Edilecekler

### `conf.php`
- Bu dosya `.gitignore` icinde.
- Format sonrasi elle geri konulmazsa proje veritabanina baglanamaz.
- Icerik olarak su alanlar kontrol edilmeli:
  - MySQL host
  - port
  - database
  - username
  - password
  - `APP_ENV`
  - `APP_KEY`
  - `COOKIE_SECRET`
  - `COOKIE_DOMAIN`

### `composer.lock`
- Bu dosya artik ignore edilmemeli.
- Dependency surumlerinin format sonrasi kaymamasi icin projeyle birlikte tutulmali.

### `vendor` ve `node_modules`
- Bunlar tekrar kurulabilir.
- Ama internet veya surum uyumsuzlugu riski varsa format oncesi ayrica arsiv almak mantiklidir.

### Gorseller ve yuklenen dosyalar
- Login ve marka gorselleri `htdocs\img` altinda.
- Burasi ayri yedeklenmeli.

## 3. Format Sonrasi Kurulum Sirasi

### 3.1 Temel kurulum
1. Laragon kur.
2. PHP `8.0.x` kur ve aktif et.
3. MySQL kur ve Laragon icinde aktif et.
4. Composer kur.
5. Gerekirse Node.js kur.

### 3.2 Projeyi geri koy
1. Proje klasorunu tekrar `C:\laragon\www` altina yerlestir.
2. `conf.php` dosyasini geri koy.
3. Gorsel ve ozel asset klasorlerini geri koy.

### 3.3 PHP bagimliliklari
Calistir:

```powershell
cd C:\laragon\www
composer install
```

### 3.4 Frontend bagimliliklari
Sadece asset rebuild gerekiyorsa:

```powershell
cd C:\laragon\www
npm install
```

Gerekirse:

```powershell
grunt
```

Not:
- Proje mevcut haliyle `node_modules` zaten klasorde olabilir.
- Sadece CSS/JS rebuild ihtiyaci varsa Node tarafina tekrar don.

### 3.5 Veritabani geri yukleme

#### En guvenli yontem
1. Format oncesi aldigin canli dump'i import et.
2. Ardindan uygulama acilsin ve temel ekranlar kontrol edilsin.

#### Sifirdan kurulum gerekiyorsa
1. `db.sql` import et.
2. Sonra `SCHEMA_SYNC_APPLY_ORDER.md` sirasina gore `schema_sync_*.sql` dosyalarini uygula.

## 4. Format Sonrasi Kontrol Listesi

### Ilk calisma kontrolu
- `http://localhost/` aciliyor mu
- login sayfasi aciliyor mu
- signup sayfasi aciliyor mu
- `/map` aciliyor mu
- admin ops aciliyor mu

### Teknik kontrol
- `composer install` hatasiz mi
- `php -m` icinde gerekli extensionlar var mi
- `tmp` klasorunde yazma sorunu var mi
- `app.log` veya PHP fatal var mi

### Uygulama kontrolu
- admin hesabiyla login
- shout feed
- market
- company
- work offers
- secimler
- map
- forgot password
- remember me

### Beta kontrolu
- `BETA_QUICK_TEST_FLOW.md`
- `BETA_FINAL_SMOKE_SCENARIOS.md`
- `BETA_DAY0_MONITORING.md`

## 5. Onerilen Minimum Guvenlik Paketi

Format oncesi su 3 adimi kesin yap:

1. Tum proje klasorunu zip al.
2. Veritabanini ayrica dump al.
3. `conf.php` dosyasini ayri ve gorunur bir yere kopyala.

## 6. Hemen Yapilabilecek Iyilestirmeler

Istersen bunlari da ekleyebiliriz:
- otomatik veritabani backup script'i
- tek komutla format sonrasi kurulum script'i
- `conf.local.php` veya `.env` tabanli daha temiz config yapisi
- Git repo baslatip uzak yedek almak
- `vendor` ve `node_modules` icermeyen temiz release paketi hazirlamak
