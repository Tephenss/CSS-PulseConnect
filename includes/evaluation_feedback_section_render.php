<?php
declare(strict_types=1);
/** @var array<string, mixed> $section */
/** @var int $responseCount */
/** @var list<array<string, mixed>> $piePaths */
/** @var list<array<string, mixed>> $pieLabels */
/** @var list<string> $uniqueBlocks */
/** @var list<string> $studentIdsList */
/** @var list<string> $namesList */
/** @var list<array{label:string,color:string,count:int}> $yearLegend */
$feedbackExportMode = $feedbackExportMode ?? false;
$feedbackListStyle = $feedbackExportMode ? 'max-height:none;overflow:visible;' : '';
$commentListStyle = $feedbackExportMode ? 'max-height:none;overflow:visible;' : 'max-height: 16rem;';
?>
        <div class="rounded-3xl border border-zinc-200 bg-white p-6 shadow-sm space-y-5">
          <div>
            <h3 class="text-xl font-bold text-zinc-900 tracking-tight"><?= htmlspecialchars((string) ($section['label'] ?? 'Feedback')) ?></h3>
            <p class="text-sm mt-1 text-zinc-500"><?= htmlspecialchars((string) ($section['description'] ?? '')) ?></p>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm">
              <p class="text-[11px] font-bold uppercase tracking-wider text-zinc-500">Participants</p>
              <p class="text-2xl font-black text-zinc-900 leading-tight mt-1"><?= (int) ($section['total_participants'] ?? 0) ?></p>
            </div>
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 shadow-sm">
              <p class="text-[11px] font-bold uppercase tracking-wider text-emerald-700">Answered</p>
              <p class="text-2xl font-black text-emerald-900 leading-tight mt-1"><?= $responseCount ?></p>
            </div>
            <div class="rounded-2xl border border-amber-200 bg-amber-50 p-4 shadow-sm">
              <p class="text-[11px] font-bold uppercase tracking-wider text-amber-700">Pending</p>
              <p class="text-2xl font-black text-amber-900 leading-tight mt-1"><?= (int) ($section['pending_participants'] ?? 0) ?></p>
            </div>
          </div>

          <?php if (empty($section['has_feedback'])): ?>
            <div class="rounded-3xl bg-zinc-50 border border-zinc-200 p-10 text-center">
              <h4 class="text-lg font-bold text-zinc-900 mb-1">No Feedback Yet</h4>
              <p class="text-sm text-zinc-500">No attendee has submitted feedback for this section yet.</p>
            </div>
          <?php else: ?>
            <!-- Year Level Pie (percent inside slices; full color legend on the side) -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-5">
              <div class="mb-4">
                <h4 class="text-base font-bold text-zinc-900">Year Level</h4>
                <p class="text-sm text-zinc-500"><?= $responseCount ?> responses</p>
              </div>
              <div class="<?= $feedbackExportMode ? '' : 'flex flex-col md:flex-row items-center gap-8 md:gap-12' ?>"<?= $feedbackExportMode ? ' style="display:flex;flex-direction:row;align-items:center;gap:3rem;flex-wrap:wrap;"' : '' ?>>
                <div class="<?= $feedbackExportMode ? '' : 'w-56 h-56 shrink-0' ?>"<?= $feedbackExportMode ? ' style="width:224px;height:224px;flex:0 0 224px;"' : '' ?>>
                  <?php if (count($piePaths) > 0): ?>
                    <svg viewBox="0 0 200 200"<?= $feedbackExportMode ? ' width="224" height="224" class="feedback-export-pie-chart" style="display:block;width:224px;height:224px;"' : ' class="w-full h-full drop-shadow-sm"' ?> role="img" aria-label="Year level distribution pie chart">
                      <?php foreach ($piePaths as $path): ?>
                        <path d="<?= htmlspecialchars((string) $path['d']) ?>" fill="<?= htmlspecialchars((string) $path['color']) ?>" stroke="#fff" stroke-width="2.5"></path>
                      <?php endforeach; ?>
                      <?php foreach ($pieLabels as $label): ?>
                        <?php if (empty($label['show'])) {
                            continue;
                        } ?>
                        <text
                          x="<?= htmlspecialchars((string) $label['x']) ?>"
                          y="<?= htmlspecialchars((string) $label['y']) ?>"
                          text-anchor="middle"
                          dominant-baseline="middle"
                          fill="#ffffff"
                          font-size="15"
                          font-weight="700"
                          style="font-family: system-ui, -apple-system, Segoe UI, sans-serif;"
                        ><?= htmlspecialchars((string) $label['text']) ?></text>
                      <?php endforeach; ?>
                    </svg>
                  <?php else: ?>
                    <div class="w-full h-full rounded-full bg-zinc-100 border border-zinc-200"></div>
                  <?php endif; ?>
                </div>
                <div class="feedback-year-legend"<?= $feedbackExportMode ? ' style="display:flex;flex-direction:column;gap:12px;min-width:10rem;"' : '' ?>>
                  <?php foreach ($yearLegend as $leg): ?>
                    <?php
                      $legCount = (int) ($leg['count'] ?? 0);
                      $legPct = $responseCount > 0 && $legCount > 0
                          ? round(($legCount / $responseCount) * 100, 1)
                          : 0.0;
                      $legColor = (string) ($leg['color'] ?? '#34A853');
                    ?>
                    <div class="feedback-year-legend-item" style="display:flex;align-items:center;gap:12px;">
                      <?php if ($feedbackExportMode): ?>
                        <span
                          class="feedback-export-legend-dot"
                          style="display:inline-block;width:14px;height:14px;min-width:14px;border-radius:50%;background-color:<?= htmlspecialchars($legColor) ?>;border:1px solid #d4d4d8;-webkit-print-color-adjust:exact;print-color-adjust:exact;"
                          aria-hidden="true"
                        ></span>
                      <?php else: ?>
                        <span class="feedback-year-legend-dot" style="background: <?= htmlspecialchars($legColor) ?>"></span>
                      <?php endif; ?>
                      <span class="text-sm font-medium text-zinc-800">
                        <?= htmlspecialchars((string) ($leg['label'] ?? '')) ?>
                        <?php if ($feedbackExportMode && $legCount > 0): ?>
                          <span style="color:#52525b;font-weight:700;"> · <?= $legPct == (int) $legPct ? (int) $legPct : number_format($legPct, 1) ?>%</span>
                        <?php endif; ?>
                      </span>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>

            <!-- Student ID / Evaluated By / Block — each list scrollable -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 space-y-5">
              <div>
                <h4 class="text-base font-bold text-zinc-900">Respondents</h4>
                <p class="text-sm text-zinc-500">Student ID, Evaluated By, and unique Blocks that submitted feedback.</p>
              </div>

              <div>
                <h5 class="text-sm font-bold text-zinc-800 mb-1">Student ID</h5>
                <p class="text-xs text-zinc-500 mb-2"><?= count($studentIdsList) ?> responses</p>
                <div class="feedback-scroll-list space-y-2"<?= $feedbackListStyle !== '' ? ' style="' . htmlspecialchars($feedbackListStyle) . '"' : '' ?>>
                  <?php foreach ($studentIdsList as $studentNo): ?>
                    <div class="rounded-xl bg-zinc-100 px-3 py-2 text-sm font-medium text-zinc-800"><?= htmlspecialchars((string) $studentNo) ?></div>
                  <?php endforeach; ?>
                  <?php if (count($studentIdsList) === 0): ?>
                    <p class="text-sm text-zinc-500">No student IDs.</p>
                  <?php endif; ?>
                </div>
              </div>

              <div>
                <h5 class="text-sm font-bold text-zinc-800 mb-1">Evaluated By</h5>
                <p class="text-xs text-zinc-500 mb-1">Name (Ex. JUAN DELA CRUZ)</p>
                <p class="text-xs text-zinc-500 mb-2"><?= count($namesList) ?> responses</p>
                <div class="feedback-scroll-list space-y-2"<?= $feedbackListStyle !== '' ? ' style="' . htmlspecialchars($feedbackListStyle) . '"' : '' ?>>
                  <?php foreach ($namesList as $respondentName): ?>
                    <div class="rounded-xl bg-zinc-100 px-3 py-2 text-sm font-medium text-zinc-800"><?= htmlspecialchars((string) $respondentName) ?></div>
                  <?php endforeach; ?>
                  <?php if (count($namesList) === 0): ?>
                    <p class="text-sm text-zinc-500">No names.</p>
                  <?php endif; ?>
                </div>
              </div>

              <div>
                <h5 class="text-sm font-bold text-zinc-800 mb-1">Block</h5>
                <p class="text-xs text-zinc-500 mb-1">Unique blocks that answered (ex. SD 1A)</p>
                <p class="text-xs text-zinc-500 mb-2"><?= count($uniqueBlocks) ?> <?= count($uniqueBlocks) === 1 ? 'block' : 'blocks' ?></p>
                <div class="feedback-scroll-list space-y-2"<?= $feedbackListStyle !== '' ? ' style="' . htmlspecialchars($feedbackListStyle) . '"' : '' ?>>
                  <?php foreach ($uniqueBlocks as $blockLabel): ?>
                    <div class="rounded-xl bg-zinc-100 px-3 py-2 text-sm font-medium text-zinc-800"><?= htmlspecialchars((string) $blockLabel) ?></div>
                  <?php endforeach; ?>
                  <?php if (count($uniqueBlocks) === 0): ?>
                    <p class="text-sm text-zinc-500">No blocks recorded.</p>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <!-- Indicators / rating bar graphs -->
            <?php if (!empty($section['rating_analytics'])): ?>
              <div class="rounded-2xl <?= $feedbackExportMode ? '' : 'overflow-hidden' ?> border border-zinc-200"<?= $feedbackExportMode ? ' style="overflow:visible;"' : '' ?>>
                <div class="feedback-section-head px-5 py-3">
                  <h4 class="text-sm font-black tracking-widest uppercase text-white">Indicators</h4>
                </div>
                <div class="bg-zinc-50 p-4 space-y-4">
                  <?php foreach (($section['rating_analytics'] ?? []) as $item): ?>
                    <?php
                      $maxCount = max(1, (int) ($item['max_count'] ?? 1));
                      $yMax = feedback_rating_y_max($maxCount);
                      $itemCount = (int) ($item['count'] ?? 0);
                    ?>
                    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm feedback-indicator-card"<?= $feedbackExportMode ? ' style="break-inside:avoid-page;page-break-inside:avoid;"' : '' ?>>
                      <div class="mb-4">
                        <p class="text-xs font-bold uppercase tracking-wider text-zinc-500 mb-1">Indicator</p>
                        <h5 class="text-base font-bold text-zinc-900"><?= htmlspecialchars((string) ($item['question_text'] ?? '')) ?></h5>
                        <p class="text-sm text-zinc-500 mt-0.5"><?= $itemCount ?> responses<?= $itemCount > 0 ? ' · Avg ' . htmlspecialchars((string) ($item['avg'] ?? 0)) . '/5' : '' ?></p>
                      </div>

                      <div class="feedback-chart-wrap"<?= $feedbackExportMode ? ' style="overflow:visible;width:100%;max-width:560px;margin:0 auto;"' : '' ?>>
                        <?= feedback_rating_bar_chart_svg(is_array($item['bars'] ?? null) ? $item['bars'] : [], $yMax) ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>

            <!-- Comments & Suggestions -->
            <?php if (!empty($section['comments']) || !empty($section['suggestions'])): ?>
              <div class="rounded-2xl <?= $feedbackExportMode ? '' : 'overflow-hidden' ?> border border-zinc-200"<?= $feedbackExportMode ? ' style="overflow:visible;"' : '' ?>>
                <div class="feedback-section-head px-5 py-3">
                  <h4 class="text-sm font-black tracking-widest uppercase text-white">Comments &amp; Suggestions</h4>
                </div>
                <div class="bg-zinc-50 p-4 space-y-4">
                  <?php foreach (($section['comments'] ?? []) as $item): ?>
                    <div class="rounded-2xl border border-zinc-200 bg-white p-5">
                      <h5 class="text-base font-bold text-zinc-900"><?= htmlspecialchars((string) ($item['question_text'] ?? 'Comments')) ?></h5>
                      <p class="text-sm text-zinc-500 mb-3"><?= (int) ($item['count'] ?? count($item['responses'] ?? [])) ?> responses</p>
                      <div class="feedback-scroll-list space-y-2" style="<?= htmlspecialchars($commentListStyle) ?>">
                        <?php foreach (($item['responses'] ?? []) as $response): ?>
                          <div class="rounded-xl bg-zinc-100 px-3 py-2.5">
                            <div class="text-sm text-zinc-800 whitespace-pre-line"><?= htmlspecialchars((string) ($response['answer_text'] ?? '')) ?></div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>

                  <?php foreach (($section['suggestions'] ?? []) as $item): ?>
                    <div class="rounded-2xl border border-zinc-200 bg-white p-5">
                      <h5 class="text-base font-bold text-zinc-900"><?= htmlspecialchars((string) ($item['question_text'] ?? 'Suggestions')) ?></h5>
                      <p class="text-sm text-zinc-500 mb-3"><?= (int) ($item['count'] ?? count($item['responses'] ?? [])) ?> responses</p>
                      <div class="feedback-scroll-list space-y-2" style="<?= htmlspecialchars($commentListStyle) ?>">
                        <?php foreach (($item['responses'] ?? []) as $response): ?>
                          <div class="rounded-xl bg-zinc-100 px-3 py-2.5">
                            <div class="text-sm text-zinc-800 whitespace-pre-line"><?= htmlspecialchars((string) ($response['answer_text'] ?? '')) ?></div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
