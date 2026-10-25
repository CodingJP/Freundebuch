<?php
/* =========================================================
   EINSTELLUNGEN
   Diese Datei nach config.php kopieren und dort anpassen.
   config.php wird nicht ins Git-Repo eingecheckt.
   =========================================================

   FB_ADMIN_CODE : Dein persoenlicher Admin-Code. Damit legst du Feiern an,
                   vergibst die Gaeste-Codes, schliesst Feiern ab und
                   exportierst sie. Bitte lang und geheim waehlen.

   Die Gaeste-Codes und Titel der einzelnen Feiern stellst du danach
   direkt in der App ein (mit dem Admin-Code anmelden).
*/

define('FB_ADMIN_CODE', 'BitteHierEinenLangenGeheimenCodeEintragen');

/* Nur fuer Updates von der alten Version (ein einziges Fest ohne Events):
   Steht hier noch der alte Gaeste-Code, werden die vorhandenen Seiten beim
   ersten Aufruf automatisch in eine erste Feier mit diesem Code uebernommen.

   define('FB_SECRET_CODE', 'AlterGaesteCode');
   define('FB_TITLE',       'Titel der alten Feier');
*/
