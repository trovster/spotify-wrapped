---
Prompt: Using this task below and repository images, create a DETAILED /plan for this repository. Use sub-agents to focus on the user stories, but orchestrate the epic and make sure everything works together. Propose ideas for technology, required user information (such as spotify username, api keys). Make sure the plan documents a detail readme.

I want to build a new application. This will use Spotify user data to power statistics and raise insights about listening habits. The project will initially use a Spotify export to populate the data, but then connect to the API to sync more recent information. The project will be for a *single user*, so no multi-user management is required.

The new application should document *exactly* how to setup the project, so it can be used by a non-developer. It should use technologies that are easy to use, configure, install, and serve. My familiarity is with PHP and Laravel, but it could be purely Node-based. Propose a solution for storing the data so insights and querying can be used - again, this should be easy to manage, my thought was SQLite.

Write a plan which will implement the following epic and user stories. See the screenshots in this directory.
---

—

EPIC: As a Spotify listener, I want a regularly updated "Wrapped" experience so
that I can see what has changed in my listening over different periods (1 week,
1 month, 6 months, year, all time).

—

Story 1: Generate Wrapped

As a listener, I want a new Wrapped generated each week, so that I can see how
my listening habits have changed.

Acceptance Criteria User can generate a Wrapped for the latest period. Wrapped
contains a fixed set of cards. Cards are generated from listening history.

—

Story 2: Biggest Change

As a listener, I want to know what changed most, so that I immediately learn
something interesting.

Acceptance Criteria System identifies the biggest listening change. Card
contains a headline and supporting text.

Example Clutch was your fastest-rising artist this week.

—

Story 3: New Discovery

As a listener, I want to know what I discovered recently, so that I can reflect
on my musical exploration.

Acceptance Criteria System identifies newly-played artists. One discovery card
is generated.

Example You discovered Danzig this week.

—

Story 4: Weekly Summary

As a listener, I want a summary card, so that I can understand my week at a
glance.

Acceptance Criteria Generated from available listening data. Appears as the
final card.

Example This week you explored darker metal while revisiting classic favourites.

—

Story 5: Wrapped Presentation

As a listener, I want to swipe through cards, so that the experience feels like
Wrapped.

Acceptance Criteria Cards display one at a time. User can move forwards and
backwards. Mobile-first design.

—

MVP Definition

Input: Spotify listening history

Output: 3 cards
* Biggest Change
* New Discovery
* Weekly Summary
