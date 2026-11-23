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
   (:guilabel:`Check now` in the sub-module :guilabel:`Overview`), shows the state of the connection, the domain
   confirmation and the page quota, and (in :guilabel:`Findings`) lists the open findings of the latest check.
*  **Domain confirmation** – the service only checks websites whose owner is proven. The extension serves the
   proof (a file and a meta element) for every connected site, so the confirmation is one click in the module.
*  **Embed** – the content element :guilabel:`Accessibility statement` shows the statement that the site has
   **published** in the service, as plain HTML in the page. A new version published in the service reaches the page
   without any change in TYPO3.
*  **Statement page** – the sub-module :guilabel:`Statement` shows the published statement and the pages that show
   it, and can create a hidden page that already carries the element.
*  **Way to the statement** – the sub-module :guilabel:`Way to the statement` lists the steps from the latest check
   to a published statement, as the service names them. Manual check questions, who declares, the applicability
   check and the mandatory details are filled in there, and at the end the statement can be published from TYPO3
   (see :ref:`introduction-way`).

.. _introduction-way:

Way to the statement
====================

This sub-module needs a token for which an administrator of the organization has granted the rights to *prepare*
and to *publish* a statement in the service (tab :guilabel:`Einbindung`). Without them the module shows sentences
that say where to grant the right instead of the forms. The extension never claims a right the token does not have;
if the right is withdrawn later, the service refuses the call and the module says so.

At the end of the way, a preview of the draft is shown (:guilabel:`Preview of the draft`), then the extension asks
for the statement on conformance (a deviation from the determined one needs a reason). :guilabel:`Publish version n
now` publishes it. A double click produces one version, and a draft that changed in the meantime is shown again
instead of being published unseen. Pages with the content element show the new version at once. The version records
the name of the backend user (real name, otherwise the user name) as the person who released it.

What it does not do
===================

*  **No overlay.** The extension adds no widget, no script and no style to the pages of the site. It does not make
   a website accessible; it reports what is not.
*  **No local checking engine.** Nothing is measured inside TYPO3 and nothing is sent to the service except what the
   functions above need (connection, checks, domain confirmation, the entries of the way to the statement and its
   release). The checks run in the service, against the public website.
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
