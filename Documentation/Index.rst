.. include:: /Includes.rst.txt

.. _start:

=====================================================
Barrierepruefung.de – accessibility statement
=====================================================

:Extension key:
   barrierepruefung

:Package name:
   barrierepruefungde/typo3

:Version:
   |release|

:Language:
   en

:Author:
   Lukas Boc

:License:
   This document is published under the
   `GPL-2.0-or-later <https://www.gnu.org/licenses/old-licenses/gpl-2.0.html>`__ license.

:Rendered:
   |today|

----

Connects a TYPO3 site to `barrierepruefung.de <https://barrierepruefung.de>`__, a service that checks websites
for accessibility (BFSG, BITV 2.0) and generates the accessibility statement. The extension is a thin client of the
service's public API: it starts checks, shows their open findings, leads along the way to the statement (and, with a
token that has the right, publishes it), and puts the **published** statement of the site into a content element.

----

.. card-grid::
   :columns: 1
   :columns-md: 2
   :gap: 4
   :class: pb-4
   :card-height: 100

   .. card:: Introduction

      What the extension does, and what it deliberately does not do.

      .. card-footer:: :ref:`Read more <introduction>`
         :button-style: btn btn-secondary stretched-link

   .. card:: Installation

      Composer or TER, database setup, the scheduler task and the backend rights of the four sub-modules.

      .. card-footer:: :ref:`Read more <installation>`
         :button-style: btn btn-secondary stretched-link

   .. card:: Configuration

      Connect a site to its website in the service and confirm the domain.

      .. card-footer:: :ref:`Read more <configuration>`
         :button-style: btn btn-secondary stretched-link

   .. card:: Content element

      Fields of the accessibility statement element, heading levels and language.

      .. card-footer:: :ref:`Read more <content-element>`
         :button-style: btn btn-secondary stretched-link

.. toctree::
   :maxdepth: 2
   :titlesonly:
   :hidden:

   Introduction/Index
   Installation/Index
   Configuration/Index
   ContentElement/Index
