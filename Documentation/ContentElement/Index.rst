.. include:: /Includes.rst.txt

.. _content-element:

===============
Content element
===============

The element :guilabel:`Accessibility statement` (content type ``barrierepruefung_erklaerung``) is in the new content
element wizard under :guilabel:`Special elements`. It shows the statement published for the connected site the page
belongs to. The element has no field for a header: the statement carries its own headings.

Fields
======

:guilabel:`Part`
   Which part of the statement is shown:

   *  :guilabel:`Whole statement` – all sections.
   *  :guilabel:`Only the content that is not accessible` – the section on the known restrictions
      ("Nicht barrierefreie Inhalte" or "Bekannte Einschränkungen").
   *  :guilabel:`Only feedback and contact` – the section "Feedback und Kontaktangaben" or "Kontakt bei Barrieren".

:guilabel:`First heading level`
   h2 (default), h3 or h4. The headings of the statement start at this level and never skip a level. Choose the
   level that fits below the headings of your page: the page keeps its single ``h1``, and the element never
   outputs one.

:guilabel:`Version and date`
   Adds a closing line (``<p class="a11y-erklaerung-stand">`` with a ``<time datetime>``) with the version of the
   statement and the date it was published. Yes by default.

Output
======

The statement is rendered as HTML inside ``<div class="a11y-erklaerung">``. The extension passes the text
through an HTML sanitizer and adds no inline style, no stylesheet and no script. The attribution line that the
service delivers with the statement is appended unchanged for as long as the service delivers it.

Language
========

A statement is approved in one language and **shown in that language**. The extension does not translate it. If the
language of the page differs from the language of the statement, the wrapper carries a ``lang`` attribute with the
language of the statement, so screen readers pronounce it correctly; otherwise there is no ``lang`` attribute.

The element of a translated page therefore shows the same statement as the one of the default language. Statements
in another language are a matter of the service, not of the extension.

Versions
========

After a new version is published in the service, the page shows it with the next scheduler run (see
:ref:`installation-scheduler`) or at the latest when its cache lifetime has run out. If the service cannot be
reached, the page shows the last version it received, with that version's own date. A version never disappears
because the service is down.

For integrators: styling
========================

The element has **no styling of its own** (by design: no inline style, no stylesheet). It inherits what your site
package defines. Two things to check in your theme:

*  Heading sizes. In :composer:`typo3/theme-camino` (the default theme of TYPO3 14.3) ``h2`` is small and ``h3`` is
   40 px, so a statement set to :guilabel:`h3` looks larger than one at :guilabel:`h2`. Choose the first heading level
   by the document structure, then adjust the sizes in the site package.
*  Long words. In Camino, long words in headings run over the edge at a viewport of 320 px. That is a concern of the
   site package, for example ``hyphens: auto`` and ``overflow-wrap: anywhere`` for headings.

The condition for Camino applies only if the site lists the set ``typo3/theme-camino`` directly as a dependency.

Selectors you can use: ``.a11y-erklaerung`` (wrapper), ``.a11y-erklaerung-stand`` (version and date line),
``.a11y-erklaerung-nicht-verfuegbar`` (note for a site that has no statement yet).
