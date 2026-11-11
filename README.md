# Finobe

This branch includes the essential administration controls and dedicated user management pages. See [admin setup and behavior](docs/admin-panel.md) and the [control inventory](docs/admin-controls.md). The captured Finobe blog and its admin publishing workflow are documented in [blog setup](docs/blog.md).

## Finobe.NET by Bepistown
  
# [ INFORMATION ]
Happy halloween! and this is our gift to you. Finobe.NET is a really shit revival, and it's been in-development since 2021.  
  
# [ SETUP ]
You need redis.  
Run these commands onto terminal: composer install, npm install  
rename the .env.example to just .env,configure the .ENV file to your liking.  
after you're done setting up the .ENV file enter this into the terminal: php artisan key:generate.  
after that do php artisan migrate then go to config/database.php and change this  
'database' => env('DB_DATABASE', 'CHANGE THIS TO YOUR DATABASE NAME'),  
  
Use caddy or NGINX to host it.  
Have fun!  

# [ CREDITS ]
\- Original Dev Team -   
Instance - Giving Aesthetiful money to buy things for Finobe.NET  
Co-Owner, Developer (The one who made this trainwreck of a revival) - Aesthetiful  
Client Developer, Leaker - Karma  
~~Client Developer~~ Dude who didn't do anything to contribute to development - WaterBoi  
  
\- Bepistown -  
watcha27 - rebuilt db  
ThugShaker3D - reuploaded source code this to github  
Karma - leaking website source code

# [ ISSUES ]
Faulty placelauncher, please hit us with a pull request.  

# [ TODO ]
Replace Soap requests with something other, PHP's SoapClient halts the website.  

## Ops note: `clientsettings.api.<domain>`

`clientsettings.api.finobe.wtf` must resolve and return the FastFlags JSON (`200`). It needs
**all three**: a proxied Cloudflare DNS `CNAME clientsettings.api` → the finobe tunnel
(`d7f352c6-5327-4f49-ab68-353bc15a66c2.cfargotunnel.com`), a matching tunnel ingress entry
(`clientsettings.api.finobe.wtf → http://localhost:80`, before the `http_status:404` catch-all),
and `clientsettings.api.finobe.wtf` in the nginx `server_name`. See
`docs/gameservers-and-security.md` §4.
