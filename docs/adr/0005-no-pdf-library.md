# Reports are HTML with a print stylesheet, not a generated PDF

NextFleet ships no server-side PDF renderer.
Reports are HTML, and the browser makes the PDF.
CSV covers the machine-readable path.

A PHP PDF library would need a licence review against the AGPL.
A renderer that loads remote resources is an SSRF hole with a friendly name.

Do not "fix" this by adding a renderer.
Revisit it only when a scheduled report needs a file that nobody is there to print.
