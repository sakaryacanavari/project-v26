# Kapali Beta Quick Test Flow

Bu belge son kontrol turunu hizli kosmak icin hazirlandi.

## Hesap A
- normal oyuncu

## Hesap B
- ikinci oyuncu
- mumkunse admin yetkili test hesabi

## Sira

### 1. Auth
#### Hesap A
1. `signup`
2. `login`
3. `settings`
4. dili degistir

Beklenen:
- kayit olur
- giris yapar
- ayar kaydi calisir
- dil degisikligi kalici olur

### 2. Core
#### Hesap A
1. `gyms`
2. bir egitim yap
3. ayni gun ikinci kez dene
4. `storage`

Beklenen:
- ilk egitim olur
- ikinci deneme limit verir
- storage acilir

### 3. Economy
#### Hesap A
1. `mycompanies`
2. is ilani ac
3. `storage`
4. bir item markete koy

#### Hesap B
1. `work-offers`
2. Hesap A'nin ilanina basvur
3. `marketplace`
4. Hesap A'nin itemini satin al

Beklenen:
- is ilani olusur
- basvuru calisir
- market buy/sell calisir

### 4. Race Test
#### Hesap A ve Hesap B
1. ayni bos is ilanina ayni anda bas

Beklenen:
- sadece biri isi alir
- digeri temiz hata alir

### 5. Shouts
#### Hesap A
1. ana sayfada shout at

#### Hesap B
1. shouta reply at
2. shout icinde `@HesapA` mention yaz
3. shoutu raporla

#### Hesap A
1. bildirimlere bak
2. `Son / Ulkem / Populer` sekmelerini dene

Beklenen:
- reply gorunur
- mention bildirimi gelir
- sekmeler hata vermez

### 6. Politics
#### Hesap A
1. `parties`
2. bir partiye basvur
3. `congress`
4. `elections`

#### Hesap B
1. lider/admin ise parti basvurusunu gor

Beklenen:
- partiler acilir
- meclis acilir
- secimler acilir

### 7. Media
#### Hesap A
1. gazete kur
2. makale yayinla

#### Hesap B
1. makaleye yorum yap

Beklenen:
- gazete ve makale akisinda hata yok

### 8. War
#### Hesap A
1. `wars`

Beklenen:
- savas sayfasi acilir

### 9. Ops
#### Hesap B veya admin
1. `admin/ops`
2. `Kapali Beta Sagligi`
3. cron listesi
4. reported shoutlar

Beklenen:
- blocker alani temiz
- cron hata yok
- reported shout girdisi gorunur

## Son Karar

Tum bloklar temizse:
- [C:\laragon\www\BETA_GO_NO_GO.md](/C:/laragon/www/BETA_GO_NO_GO.md) icinde `GO`

Bir blok fail ise:
- `NO-GO`
- log:
  - [C:\laragon\www\tmp\logs\app.log](/C:/laragon/www/tmp/logs/app.log)
  - [C:\laragon\www\tmp\logs\profile.log](/C:/laragon/www/tmp/logs/profile.log)
