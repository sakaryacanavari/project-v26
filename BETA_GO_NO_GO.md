# Kapali Beta Go / No-Go

Bu belge acilis kararini vermek icin kullanilir.

## Mevcut Durum

### GO sinyalleri
- [x] Beta dokumanlari mevcut
- [x] Schema uygulama sirasi mevcut
- [x] Cron PHP parse kontrolleri temiz
- [x] Admin ops ekraninda beta saglik ozeti var
- [x] Market / work-offers / company tarafinda temel exploit korumalari guclendirildi
- [x] Shout ve moderasyon minimum paketleri aktif

### NO-GO sinyalleri
- [ ] Gercek iki hesapla smoke test tamamlandi
- [ ] Ekonomi yarisi test edildi
- [ ] Secimler / meclis / siyaset akislarinda gercek kullanici testi tamamlandi
- [ ] Admin ops ekraninda blocker alaninin temiz oldugu dogrulandi

## Acilisi Bloklayan Son Kontroller

### 1. Auth
- [ ] signup
- [ ] login
- [ ] logout
- [ ] settings save
- [ ] dil degisikligi

### 2. Ekonomi
- [ ] work-offers aciliyor
- [ ] ayni is ilanina iki hesapla ayni anda basvuru test edildi
- [ ] market buy calisiyor
- [ ] storage -> market sell negatif envanter uretmiyor
- [ ] ayni sirket icin ikinci bos ilan acilamiyor

### 3. Sosyal
- [ ] shout feed aciliyor
- [ ] Son / Ulkem / Populer sekmeleri calisiyor
- [ ] reply calisiyor
- [ ] mention calisiyor
- [ ] reported sekmesi admin icin dogru doluyor

### 4. Siyaset
- [ ] meclis aciliyor
- [ ] secimler aciliyor
- [ ] baskanlik secimi fazlari gorunuyor
- [ ] kongre secimi akisi hata vermiyor

### 5. Ops
- [ ] admin ops aciliyor
- [ ] beta saglik ozeti gorunuyor
- [ ] blocker alani temiz
- [ ] cron hata / gecikme yok
- [ ] app log yolu gorunuyor

## Acilis Karari

### Mevcut karar
**NO-GO**

### Neden
Kod ve operasyon altyapisi buyuk oranda hazir olsa da, gercek oyuncu akislarinin son smoke kosusu tamamlanmis degil.

### GO demek icin gereken son adim
[C:\laragon\www\EARLY_ACCESS_SMOKE_CHECKLIST.md](/C:/laragon/www/EARLY_ACCESS_SMOKE_CHECKLIST.md) gercek iki hesapla kosulup yukaridaki kutular isaretlenmeli.

## Hemen Acilisa En Yakin Yol
1. Iki test hesabi ile smoke checklist kos
2. Admin ops ekraninda blocker alani kontrol et
3. Ekonomi yarisi testlerini yap
4. Yeni kritik hata yoksa karari `GO`ya cek
