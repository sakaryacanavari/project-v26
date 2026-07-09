# Early Access Smoke Checklist

Bu liste kapali beta acilisindan once ve her buyuk guncellemeden sonra gercek hesaplarla kosulmalidir.

## Auth
- `signup` yeni kullanici olusturuyor
- `login` dogru hesapla giris yapiyor
- yanlis sifre dogru hata veriyor
- `logout` oturumu kapatiyor

## Core Player
- `gyms` sayfasi aciliyor
- gun icinde sadece 1 egitim yapilabiliyor
- ana sayfa egitim durumunu dogru gosteriyor
- `storage` aciliyor
- `settings` kaydediyor
- dil degisikligi calisiyor

## Economy
- `work-offers` aciliyor
- ise basvuru yapilabiliyor
- ayni is ilanina iki hesap ayni anda girince tek kazanan oluyor
- aktif iste `work` calisiyor
- gunluk maas dogru currency kolonuna yaziliyor
- `marketplace` aciliyor
- marketten satin alma calisiyor
- storage -> market satisinda envanter negatif olmuyor
- ayni item icin cift satis istegi acik uretmiyor
- sirketten ayni anda iki aktif bos is ilani acilamiyor

## Politics
- parti listesi aciliyor
- parti basvurusu lider paneline dusuyor
- kabul/red bildirime gidiyor
- partiden ayrilma calisiyor
- koalisyon karti aciliyor
- secimler sayfasi aciliyor
- baskanlik secimi takvim fazlari dogru gorunuyor
- kongre secimi akisi hata vermiyor

## Social
- ana sayfa shout feed aciliyor
- `Son / Ulkem / Populer` sekmeleri calisiyor
- reply ekleme calisiyor
- mention linke donusuyor
- mention bildirimi gidiyor
- reported sekmesi admin icin doluyor

## Media
- gazete kurma calisiyor
- makale yayinlama sonrasi dogru makale sayfasina gidiyor
- haber listesi aciliyor
- makale yorumu calisiyor

## War
- `wars` sayfasi aciliyor
- aktif savas varsa detaylar gorunuyor
- savasma istegi hatasiz donuyor

## Ops
- `admin/ops` aciliyor
- uygulama log yolu gorunuyor
- beta saglik ozeti gorunuyor
- gym sync araci calisiyor
- party repair araci calisiyor
- makale kaldirma calisiyor
- cron hatasi ve gecikme ozeti gorunuyor

## Final Gate
- kritik akislerde fatal error yok
- ana sayfa warnings uretmiyor
- bug report aciliyor
- cron dosyalari parse temiz
- `scripts/beta_health_check.php` temiz sonuc donuyor
