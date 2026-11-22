.. include:: /Includes.rst.txt

.. _installation:

============
Installation
============

Composer
========

.. code-block:: bash

   composer require lubomedia/barrierepruefung-typo3
   vendor/bin/typo3 extension:setup

:bash:`extension:setup` creates the database table of the connection and activates the extension. In a classic
(non-Composer) installation, use :guilabel:`Admin Tools > Extensions` and :guilabel:`Admin Tools > Maintenance >
Analyze Database Structure` instead.

TYPO3 Extension Repository
==========================

Download the extension ``barrierepruefung`` from the
`TYPO3 Extension Repository <https://extensions.typo3.org/extension/barrierepruefung>`__ or install it in
:guilabel:`Admin Tools > Extensions`, activate it and run :guilabel:`Analyze Database Structure`.

.. note::

   TYPO3 14.3 reports a deprecation for ``ext_emconf.php``. The file stays: TYPO3 13.4 and the upload to the
   TYPO3 Extension Repository need it.

After an update
===============

Run :bash:`vendor/bin/typo3 extension:setup` or :guilabel:`Analyze Database Structure` after every update.

.. _installation-scheduler:

Scheduler task (recommended)
============================

Without a scheduler task a page shows the statement fetched on its last view, for at most the time the version is
valid (one hour at most). For prompt updates, create a task in the scheduler (system extension
:composer:`typo3/cms-scheduler`):

#. :guilabel:`System > Scheduler`, add a task of type :guilabel:`Execute console commands`.
#. Choose the command :bash:`barrierepruefung:aktualisieren`.
#. Frequency: hourly (for example ``0 * * * *``).

The command asks the service for the published statement of every connected site (usually only a ``304`` answer)
and removes exactly those pages from the page cache that show a new version. If the service is down, the exit code
stays ``0`` and the last good version stays on the page. Only if no token can be decrypted at all (see
:ref:`configuration-encryption-key`) does the command report a failure.

You can also run it by hand:

.. code-block:: bash

   vendor/bin/typo3 barrierepruefung:aktualisieren

.. _installation-rights:

Backend rights
==============

The module group :guilabel:`Accessibility` consists of three sub-modules: :guilabel:`Overview`,
:guilabel:`Findings` and :guilabel:`Statement`. **Every sub-module needs its own right.**

For a backend user group, open :guilabel:`Access Rights > Modules` and tick the sub-modules the group should see.
A group that only has :guilabel:`Overview` does not see :guilabel:`Findings` and :guilabel:`Statement`.

Besides the module right, the person needs a web mount that contains the site. A person without such a mount does
not see the site in the selection and cannot start anything for it, even with a hand-made ``site`` parameter: the
token acts for the whole organization in the service.

Creating the statement page needs the usual rights for creating pages and content elements of this type; TYPO3's
data handler checks them, not the extension.
