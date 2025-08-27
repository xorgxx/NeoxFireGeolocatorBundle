# (Optionnel) Installer PHPUnit si non présent
composer require --dev phpunit/phpunit:^10

# Installer les dépendances du projet
composer install

# Lancer tous les tests (en utilisant phpunit.xml / phpunit.xml.dist)
 php vendor\bin\phpunit

# Lancer une suite spécifique (si définie: unit, integration, functional, e2e)
 php vendor\bin\phpunit --testsuite unit
 php vendor\bin\phpunit --testsuite integration
 php vendor\bin\phpunit --testsuite functional
 php vendor\bin\phpunit --testsuite e2e

# Lancer un fichier de test précis
 php vendor\bin\phpunit tests/Unit/GeoRuleEvaluatorTest.php

# Lancer un test/méthode spécifique (via filtre)
 php vendor\bin\phpunit --filter 'should_authorize_when_ip_whitelisted'

# Affichage TestDox (sortie lisible)
 php vendor\bin\phpunit --testdox

# Couverture texte en console (nécessite Xdebug/pcov)
 php vendor\bin\phpunit --coverage-text

# Générer un rapport HTML de couverture
 php vendor\bin\phpunit --coverage-html var/coverage/

# Arrêter à la première erreur/échec
 php vendor\bin\phpunit --stop-on-failure
