# Kapali Beta Final Smoke Scenarios

Bu belge iki hesapla uygulanacak son acilis oncesi test akisini tanimlar.

## Test Hesaplari

### Hesap A
- Rol: normal vatandas
- Amac: temel oyuncu akislarini dogrulamak

### Hesap B
- Rol: ikinci oyuncu / gerekiyorsa admin
- Amac: yaris durumu, mention, reply, reported ve secim akislarini dogrulamak

## Kurallar
- Her adimda beklenen sonuc alinmazsa test durdurulur.
- Hata aninda:
  - [C:\laragon\www\tmp\logs\app.log](/C:/laragon/www/tmp/logs/app.log)
  - [C:\laragon\www\templates\admin\ops.html.twig](/C:/laragon/www/templates/admin/ops.html.twig)
  - [C:\laragon\www\BETA_GO_NO_GO.md](/C:/laragon/www/BETA_GO_NO_GO.md)
  birlikte kontrol edilir.

---

## 1. Auth ve Core

### 1.1 Signup / Login
1. Hesap A ile signup yap
2. logout yap
3. ayni hesapla login yap
4. Hesap B ile login yap

Beklenen:
- signup basarili
- login basarili
- logout basarili
- fatal hata yok

### 1.2 Settings / Dil
1. Hesap A ile `settings` ac
2. dili degistir
3. sayfayi yenile

Beklenen:
- ayar kaydi calisir
- dil tercihi kalici olur
- sayfa bozulmaz

### 1.3 Gyms
1. Hesap A ile `gyms` ac
2. gunluk egitim yap
3. ikinci kez ayni gun dene

Beklenen:
- ilk egitim calisir
- ikinci denemede limit mesajı gelir

---

## 2. Ekonomi

### 2.1 Storage -> Market Sell
1. Hesap A ile `storage` ac
2. bir item sec
3. markete sat
4. ayni anda hizli ikinci kez satmayi dene

Beklenen:
- ilk satis basarili
- ikinci istek negatif envanter uretmez
- envanter sifir altina dusmez

### 2.2 Marketplace Buy
1. Hesap B ile marketplace ac
2. Hesap A'nin itemini satin al

Beklenen:
- bakiye duser
- item envantere eklenir
- seller bakiyesi artar

### 2.3 Work Offers Race
1. Hesap A ile bir sirketten is ilani ac
2. Hesap A ve Hesap B ayni ilan icin ayni anda basvursun

Beklenen:
- sadece bir hesap ise girer
- diger hesap temiz hata alir
- ayni ilan iki kiside birden aktif olmaz

### 2.4 Company Offer Guard
1. Hesap A ile ayni sirket icin ikinci aktif ilan acmaya calis

Beklenen:
- sistem reddeder

---

## 3. Social / Shouts

### 3.1 Feed
1. Hesap A ile ana sayfa ac
2. `Son`
3. `Ulkem`
4. `Populer`
sekmleri arasinda gec

Beklenen:
- baglanti hatasi yok
- sayfa yukari ziplamaz
- feed yuklenir

### 3.2 Reply
1. Hesap A shout at
2. Hesap B o shouta reply at
3. Hesap A yorumlari ac

Beklenen:
- reply gorunur
- reply bildirimi gider

### 3.3 Mention
1. Hesap B, Hesap A'yi `@nick` ile mentionlasin
2. Hesap A bildirime baksin
3. mention linkine tiklasin

Beklenen:
- bildirim gider
- mention profile link olur
- gecersiz kullanici mentioni duz metin kalir

### 3.4 Moderasyon
1. Hesap B, Hesap A'nin shoutunu raporlasin
2. admin veya admin benzeri hesap `Reported` sekmesini acsin

Beklenen:
- rapor nedeni gorunur
- rapor sayisi gorunur
- gizle / geri ac / sustur aksiyonlari calisir

### 3.5 State Decree
1. baskan hesapla resmi duyuru at
2. ana sayfada kontrol et

Beklenen:
- sadece `Baskan Mesaji` kartinda gorunur
- normal shout feed'inde tekrar etmez

---

## 4. Politics

### 4.1 Parties
1. parti listesi ac
2. Hesap A partiye basvursun
3. Hesap B lider/admin ise basvuruyu kabul veya reddetsin

Beklenen:
- basvuru gorunur
- bildirim gider

### 4.2 Congress
1. `Meclis` sayfasini ac
2. yasa kartlari yukleniyor mu kontrol et

Beklenen:
- 500 yok
- sayfa aciliyor

### 4.3 Elections
1. `Secimler` sayfasini ac
2. baskanlik / parti liderligi / kongre bloklarini kontrol et

Beklenen:
- faz bilgileri gorunur
- adaylik/oy butonlari hata vermeden acilir

---

## 5. Media

### 5.1 Newspaper
1. Hesap A gazete kursun
2. makale yayinlasin
3. Hesap B makaleye yorum yapsin

Beklenen:
- gazete kurulur
- makale dogru sayfaya gider
- yorum calisir

---

## 6. War

### 6.1 Wars
1. `wars` sayfasini ac
2. aktif savas varsa detay kutularini kontrol et

Beklenen:
- sayfa acilir
- hata vermez

---

## 7. Ops Final Check

1. admin ile `admin/ops` ac
2. `Kapali Beta Sagligi` kutusuna bak
3. cron listesine bak

Beklenen:
- blocker alani temiz
- hata veren cron yok
- geciken cron yok

---

## Son Karar

Tum bloklar temiz gecerse:
- [C:\laragon\www\BETA_GO_NO_GO.md](/C:/laragon/www/BETA_GO_NO_GO.md) icindeki karar `GO` olarak guncellenir

Herhangi bir blok fail olursa:
- karar `NO-GO` kalir
- ilgili log ve route not edilir
