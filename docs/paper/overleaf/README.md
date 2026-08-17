# SignCollect Annotation Tool v3 — LaTeX (Overleaf-ready)

LaTeX source for the technical report *"The SignCollect Annotation Tool v3:
Automatic Sign Segmentation and Gloss Spotting in the Browser"*, converted from
the print-ready HTML version.

## Files
- `main.tex` — the paper (`article` class). The three system diagrams are native
  **TikZ** (fully editable, no image files needed).
- `references.bib` — the 9 references (BibTeX).

## Open in Overleaf
1. Download the zip (`signcollect-v3-overleaf.zip`).
2. Overleaf → **New Project → Upload Project** → select the zip.
3. Set the compiler to **pdfLaTeX** (Menu → Compiler) — it's the default.
4. Recompile. BibTeX runs automatically; if citations show as `[?]`, hit
   Recompile once more.

No special packages are required beyond what Overleaf ships by default
(`newtxtext/newtxmath`, `tikz`, `booktabs`, `natbib`, `hyperref`, `microtype`).

## Local build
```bash
pdflatex main
bibtex   main
pdflatex main
pdflatex main
```

## Notes
- Citations use `natbib` numeric style (`[1]`, `[2]`, …) to match the original.
- To retarget a venue (LREC, ACL, IEEE), swap `\documentclass` and the
  bibliography style; the body, tables, and TikZ figures carry over unchanged.
