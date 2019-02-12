
Plugin ines
===========

### Installer le plugin Ines sur votre repo
- Cherry pick le commit avec le SHA `99827ad882f312f800993b58a88651981b2c1e75` sur votre branche
- Clear le cache Mautic
- Dans la fenetre des plugins, cliquer sur "installer / mettre a jour les pugins"
- Cliquer sur l'icone INES, et remplir les informations de connexion


### Crontab à installer pour la syncro temps réel

`php app/console crm:ines`