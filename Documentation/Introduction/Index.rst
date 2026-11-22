.. include:: /Includes.rst.txt

.. _introduction:

============
Introduction
============

What the extension does
=======================

The extension connects a TYPO3 site to its website in `barrierepruefung.de <https://barrierepruefung.de>`__.
The service does the work; the extension brings the results to where editors and visitors are:

*  **Check** – the backend module :guilabel:`Site Management > Accessibility` starts a check of the site
   (:guilabel:`Check now`), shows the state of the connection, the domain confirmation and the page quota, and lists
   the open findings of the latest check.
*  **Domain confirmation** – the service only checks websites whose owner is proven. The extension serves the
   proof (a file and a meta element) for every connected site, so the confirmation is one click in the module.
*  **Embed** – the content element :guilabel:`Accessibility statement` shows the statement that the site has
   **published** in the service, as plain HTML in the page. A new version published in the service reaches the page
   without any change in TYPO3.
*  **Statement page** – the module can create a hidden page that already carries the element.

What it does not do
===================

*  **No overlay.** The extension adds no widget, no script and no style to the pages of the site. It does not make
   a website accessible; it reports what is not.
*  **No local checking engine.** Nothing is measured inside TYPO3 and nothing is sent to the service except the
   requests listed above. The checks run in the service, against the public website.
*  **No automatic repair.** Findings are shown with their rule and occurrences; fixing them is a task for the
   people who maintain the templates and the content.
*  **No legal knowledge of its own.** The texts of the statement, the mandatory items and the questions come from
   the service, in the language it approved them in. A change of the law does not need a new release of the
   extension. The extension never states that a statement could be published when the token of the connection has
   no right to do so.
*  **No telemetry.** The only server the extension talks to is the one entered in the connection.

The statement never disappears
==============================

The extension keeps the last good version of the published statement in the database (table ``sys_registry``) with
its own date. If the service is not reachable, or the token is no longer valid, the page keeps showing that version.
Only a site that never received a statement shows a short note that the statement is currently not available.

Requirements
============

*  TYPO3 13.4 LTS or 14.3 LTS, PHP 8.2 to 8.5.
*  The system extension :composer:`typo3/cms-fluid-styled-content` (the element uses its frame and header
   rendering).
*  An account at `barrierepruefung.de <https://barrierepruefung.de>`__ with a website whose address matches the
   address of the TYPO3 site, and a token for it.

Step-by-step instructions are in the help area of the service
(see :ref:`configuration-connect`).
