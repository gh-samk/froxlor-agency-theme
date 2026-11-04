# Agency theme - design principles

## Users

The in-house team of a web agency, or a freelancer, running client websites
on their own server with Froxlor. They work at a desk in the browser during
the day, with Froxlor as one tab among many: quickly create a mailbox, set up
a subdomain, check whether WordPress updates are pending. In addition there
are clients who create a mailbox or a database for themselves in the customer
area and rarely see Froxlor at all.

## Purpose

Froxlor is the server panel. The Agency theme gives the whole interface
(admin and customer area, login, and pages added by extensions that use
Bootstrap's variables) a modern, calm look without changing a single Froxlor
file. Success means: people find their way immediately, get the task done
without searching, and do not feel like they are standing inside a
programmer's tool.

## Personality

Soft, friendly, dependable. A tool that radiates order and does not
intimidate: rounded shapes, gentle depth, one clear blue accent, generous
white space in the right places. Matter-of-fact in tone, plain and direct,
without marketing language.

## Anti-references

- Froxlor's stock theme: dark, heavy sidebar, hard contrasts, uneven spacing,
  underline bars below headings.
- Classic hosting panels like cPanel or Plesk with walls of tiles and
  colourful icons.
- Generic dashboard templates: purple gradients, glass effects, oversized
  metric heroes.

## Design principles

1. **Froxlor stays Froxlor.** Same structure, same paths, same texts; only
   the surface and the feel change. Anyone who knows the stock theme finds
   everything in the same place.
2. **Calm over decoration.** Colour marks action, selection and state, not
   ornament. Danger stays red and loud, everything else quiet.
3. **Depth instead of lines.** Content sits on soft surfaces above a calm
   background; dividing lines only where rows are read.
4. **Robust against updates.** Only styles and hook points, no copies of
   Froxlor templates, so that a Froxlor update breaks nothing.

## Accessibility and inclusion

WCAG 2.2 AA: text contrast at least 4.5:1 in light and dark, visible focus
rings, controls at least 36 px high, no information conveyed by colour alone.
Motion only as feedback, and switched off under `prefers-reduced-motion`.
