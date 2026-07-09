# Kapali Beta Day-0 Monitoring

Bu belge kapali beta acildiktan sonraki ilk 24 saat icin izleme planidir.

## Canli Takip Ekranlari
- [C:\laragon\www\templates\admin\ops.html.twig](/C:/laragon/www/templates/admin/ops.html.twig)
- [C:\laragon\www\tmp\logs\app.log](/C:/laragon/www/tmp/logs/app.log)
- [C:\laragon\www\tmp\logs\profile.log](/C:/laragon/www/tmp/logs/profile.log)

## Ilk 60 Dakika
- her 10 dakikada bir admin ops kontrol edilir
- cron hata/gecikme var mi bakilir
- reported shout artisi normal mi bakilir
- work offers / market / shout feed icin kullanici sikayetleri izlenir

## 1-6 Saat
- her 30 dakikada bir kontrol
- ekonomi exploit belirtileri aranir:
  - negatif envanter
  - ayni kullanicida birden fazla aktif is
  - ayni sirketten cift acik ilan
- shout tarafinda:
  - feed hata oranlari
  - mention / reply sikayetleri
  - reported baskisi

## 6-24 Saat
- saat basi kontrol
- secimler ve cron akislarina ozel bak
- yeni oyuncu rotasi ve ilk 15 dakika drop noktalarini not et

## Acil Mudahale Gerekirse
- yeni kayitlar gecici yavaslatilir
- market veya is akisi gerekiyorsa gecici olarak kapatilabilir
- reported shout hizli temizlenir
- app log kritik hata ureten route ile birlikte not edilir

## Kritik Alarm Basliklari
- login/signup patliyor
- market buy/sell bozuldu
- work-offers assign yarisi patliyor
- shout feed hata veriyor
- cronlar gecikmis
- secimler sayfasi acilmiyor

## Basarili Day-0 Olcutleri
- fatal hata raporu dusuk
- cron blocker yok
- ekonomi exploit raporu yok
- shout ve sosyal akis stabil
- oyuncu ilk 15 dakikada temel loopa girebiliyor
