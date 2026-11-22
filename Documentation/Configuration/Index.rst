.. include:: /Includes.rst.txt

.. _configuration:

=============
Configuration
=============

There is nothing to configure in YAML or TypoScript. The connection of a site is made in the backend and stored in
the database (table ``tx_barrierepruefung_verbindung``). The token is stored encrypted and never appears in the site
configuration or in a file of the project.

.. _configuration-connect:

Connect a site
==============

Prepare in the service
----------------------

#. Add the website in the service (the address must be the address of the TYPO3 site).
#. In the tab :guilabel:`Einbindung` of that website, create the API token for the TYPO3 extension (in the English interface the tab is called :guilabel:`Embedding`). Rights to
   *prepare* and *publish* a statement are granted there by an administrator of the organization; without them
   the extension only reads and starts checks.
#. Copy the **website ID** and the **token**. The token is shown once.

Connect in TYPO3
----------------

Open :guilabel:`Site Management > Accessibility > Overview`, choose the site and fill in the form. Connecting and
disconnecting is reserved for administrators of the TYPO3 installation:

:guilabel:`API address`
   The API address of the service, as shown in the :guilabel:`Einbindung` tab (``https://barrierepruefung.de/api/v1``).

:guilabel:`Website ID`
   The identifier of the website in the service.

:guilabel:`Token`
   The token of that website.

The extension compares the address of the website in the service with the base of the TYPO3 site. They match if the
host is the same (a leading ``www.`` is ignored). If not, the site is **not** connected, and the message names both
addresses. A site whose base has no host (``/``) cannot be connected: enter a complete base with a host in the site
configuration. For sites with base variants (for example one for development), the address of the variant that
matches the website in the service counts.

Step-by-step instructions with screenshots are in the help area of the service: *Schritt-für-Schritt-Anleitung im
Hilfebereich des Dienstes*, `Einrichten <https://barrierepruefung.de/hilfe/typo3-einrichten>`__,
`Einbinden <https://barrierepruefung.de/hilfe/typo3-einbinden>`__ and
`Probleme lösen <https://barrierepruefung.de/hilfe/typo3-probleme>`__.

.. _configuration-domain:

Confirm the domain
==================

The service starts a check only for a website whose domain is confirmed. In the module, :guilabel:`Confirm domain`
asks the service to look for the proof on your site. The extension serves two proofs, each with its own token:

Meta element
   Every connected site outputs ``<meta name="a11y-site-verification" content="…">`` on its start page. The element
   is added to the response of the start page after the page cache, so a start page that was cached before the
   connection shows it at once.

Proof file
   ``/.well-known/a11y-site-verification.txt`` is answered for every host the site resolves to. The body is the
   token of the file.

The extension asks for the confirmation by meta element first and by file second.

.. important::

   **Sites under a path.** If the base of the site contains a path (``https://example.org/museum/``), the proof file
   does not resolve to this site, and the service always asks for it at the root of the host. For such a site the
   domain can **only** be confirmed with the meta element of the start page.

Start a check
=============

:guilabel:`Check now` in the module header starts a check in the service. The button carries a key that makes a
double click or the back button harmless: one click is one check. Depending on the size of the site it takes a few
minutes; :guilabel:`Refresh status` shows the progress.

.. _configuration-encryption-key:

After a change of the ``encryptionKey``
=======================================

The token is encrypted with the ``encryptionKey`` of the installation. If the key changes, the token cannot be
decrypted any more. The pages keep showing the last good version of the statement, the scheduler command does not
fail for it, and the module says :guilabel:`Connect again`. Disconnect the site and connect it again with the token (a
new one, or the same, from the :guilabel:`Einbindung` tab).

Disconnect
==========

:guilabel:`Disconnect` removes the connection and the token from the database. The website in the service remains.
