# Die letzte gute Fassung der Erklärung (Aufgabe 10) liegt in sys_registry, nicht hier.

CREATE TABLE tx_barrierepruefung_verbindung (
	uid int(11) NOT NULL auto_increment,
	tstamp int(11) unsigned DEFAULT '0' NOT NULL,
	site_identifier varchar(255) DEFAULT '' NOT NULL,
	site_id varchar(26) DEFAULT '' NOT NULL,
	dienst_url varchar(255) DEFAULT '' NOT NULL,
	token_verschluesselt text,
	faehigkeiten text,
	nachweis_meta varchar(255) DEFAULT '' NOT NULL,
	nachweis_datei varchar(255) DEFAULT '' NOT NULL,
	verbunden_am int(11) unsigned DEFAULT '0' NOT NULL,
	domain_bestaetigt_am int(11) unsigned DEFAULT NULL,

	PRIMARY KEY (uid),
	UNIQUE KEY site (site_identifier)
);
