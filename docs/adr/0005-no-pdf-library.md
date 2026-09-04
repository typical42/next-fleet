# Reports are HTML with a print stylesheet, not a generated PDF

v1 ships no server-side PDF renderer. Reports render as HTML and the browser produces the PDF. A PHP
PDF library would need a licence review against AGPL-3.0-or-later, and a renderer that resolves
remote resources is an SSRF hole with a friendly name. CSV covers the machine-readable path. Do not
"fix" this by adding a renderer: it is deliberate, and it only becomes worth revisiting when a
background or scheduled report needs a file nobody is present to print.
