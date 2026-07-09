# Docker Kurulum

Bu kurulum Laragon yerine Docker ile PHP, Apache ve MySQL calistirir.

## Gerekenler

- Docker Desktop

## Ilk Calistirma

```powershell
cd D:\proje1\laragon\www
docker compose up -d --build
```

Uygulama:

```text
http://localhost:8080
```

MySQL host portu:

```text
127.0.0.1:3307
```

Veritabani bilgileri:

```text
database: proje
username: proje
password: proje
root password: root
```

## Veritabani

Ilk calistirmada `proje.sql` otomatik import edilir. Import sadece Docker volume ilk olusurken calisir.

Temizden tekrar import etmek icin:

```powershell
docker compose down -v
docker compose up -d --build
```

## Composer

`vendor` klasoru zaten varsa uygulama acilir. Temiz kurulum gerekirse:

```powershell
docker compose exec app composer install
```

## Loglar

```powershell
docker compose logs -f app
docker compose logs -f mysql
```

Uygulama log dosyasi:

```text
tmp/logs/app.log
```

## Not

`conf.php` artik `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` ortam degiskenlerini okuyabilir. Laragon disinda calismasi icin gereken ana fark budur.
