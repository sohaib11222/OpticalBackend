# Vista Express 360 – consegna email in produzione

La configurazione SMTP permette al server di inviare, ma SPF, DKIM e DMARC sono
configurazioni DNS del dominio: non possono essere create dal codice Laravel.
Completare questi passaggi nel pannello DNS che gestisce il dominio del mittente
prima di considerare la consegna in produzione conclusa.

## Mittente unico

Usare una casella del dominio aziendale, ad esempio `info@vistaexpress.it`, per
`MAIL_FROM_ADDRESS`, `ARUBA_FROM_ADDRESS` e, se appropriato, Reply-To. Non usare
una casella Gmail di test insieme a un From Aruba: questo riduce
l'allineamento DMARC e può far comparire "via aruba.it" nel destinatario.

## DNS da pubblicare

1. **SPF** – mantenere un solo record TXT SPF sul dominio. Aruba deve fornire
   il meccanismo o l'`include:` esatto per la casella usata. Non aggiungere un
   secondo record SPF.
2. **DKIM** – attivare DKIM per il dominio nel pannello Aruba e pubblicare il
   record TXT/CNAME con il selettore e la chiave forniti da Aruba. Non
   inventare il selettore o la chiave.
3. **DMARC** – iniziare con un record TXT in `_dmarc.<dominio>`, ad esempio
   una policy di monitoraggio `p=none` con un indirizzo `rua` controllato.
   Dopo aver verificato SPF e DKIM allineati, far approvare una policy più
   restrittiva (`quarantine` o `reject`) dal proprietario del dominio.

I valori esatti dipendono dalla configurazione Aruba e devono essere copiati
dal relativo pannello/documentazione. Non salvare chiavi DKIM, password SMTP o
report con dati personali nel repository.

## Verifica operativa

```bash
cd /var/www/vistaexpressbusiness/backend
php artisan optimize:clear
php artisan config:cache
sudo supervisorctl restart 'vistaexpressbusiness-worker:*'
sudo -u www-data php artisan optical:check-messaging --smtp
php artisan optical:mail-check --send-to=un-indirizzo-di-test@tuo-dominio.it
```

Controllare il messaggio ricevuto con "Mostra originale" in Gmail o un servizio
di test: SPF, DKIM e DMARC devono risultare `PASS` e il dominio From deve essere
allineato. Il risultato `Sent` nel CRM significa che Aruba ha accettato SMTP;
non prova apertura o consegna nella posta in arrivo.
