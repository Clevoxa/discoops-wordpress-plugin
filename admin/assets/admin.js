document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.discoops-ai-card').forEach((card) => {
    card.classList.add('is-ready');
  });

  const dashboardRoot = document.querySelector('[data-discoops-dashboard]');
  if (dashboardRoot && window.fetch && window.discoopsAiAdmin && window.discoopsAiAdmin.restUrl) {
    const scanButton = dashboardRoot.querySelector('[data-discoops-dashboard-scan]');
    const progressLabel = dashboardRoot.querySelector('[data-discoops-dashboard-progress-label]');
    const progressBadge = dashboardRoot.querySelector('[data-discoops-dashboard-progress-badge]');
    const progressFill = dashboardRoot.querySelector('[data-discoops-dashboard-progress-fill]');
    const averageCard = dashboardRoot.querySelector('[data-discoops-dashboard-average] .discoops-ai-dashboard__card-value');
    const analyzedCount = dashboardRoot.querySelector('[data-discoops-dashboard-analyzed]');
    const policyRiskCard = dashboardRoot.querySelector('[data-discoops-dashboard-policy-risk] .discoops-ai-dashboard__card-value');
    const clickbaitCard = dashboardRoot.querySelector('[data-discoops-dashboard-clickbait] .discoops-ai-dashboard__card-value');
    const clickbaitToggle = dashboardRoot.querySelector('[data-discoops-dashboard-toggle-clickbait]');
    const clickbaitListWrap = dashboardRoot.querySelector('[data-discoops-dashboard-clickbait-list-wrap]');
    const clickbaitList = dashboardRoot.querySelector('[data-discoops-dashboard-clickbait-list]');
    const adminLabels = (window.discoopsAiAdmin && window.discoopsAiAdmin.labels) || {};

    const renderClickbaitList = (items) => {
      if (!clickbaitListWrap || !clickbaitList) {
        return;
      }

      const rows = Array.isArray(items) ? items : [];
      if (!rows.length) {
        clickbaitList.innerHTML = `<p class="discoops-ai-dashboard__empty">${adminLabels.noClickbaitArticles || 'Aucun article clickbait dans le dernier snapshot.'}</p>`;
        clickbaitListWrap.hidden = true;
        if (clickbaitToggle) {
          clickbaitToggle.hidden = true;
        }
        return;
      }

      if (clickbaitToggle) {
        clickbaitToggle.hidden = false;
      }

      clickbaitList.innerHTML = rows.map((item) => {
        const title = String(item.title || '');
        const id = Number(item.id || 0);
        const editUrl = String(item.edit_url || '');
        const viewUrl = String(item.view_url || '');
        const actions = [];
        if (editUrl) {
          actions.push(`<a class="button button-secondary" href="${editUrl}">${adminLabels.edit || 'Modifier'}</a>`);
        }
        if (viewUrl) {
          actions.push(`<a class="button" href="${viewUrl}" target="_blank" rel="noreferrer noopener">${adminLabels.view || 'Voir'}</a>`);
        }
        return `
          <div class="discoops-ai-dashboard__list-row">
            <div class="discoops-ai-dashboard__list-copy">
              <strong>${title}</strong>
              <span>#${id}</span>
            </div>
            <div class="discoops-ai-dashboard__list-actions">${actions.join('')}</div>
          </div>
        `;
      }).join('');
    };

    const renderDashboardScan = (payload) => {
      const snapshot = (payload && payload.snapshot) || {};
      const scan = (payload && payload.scan) || {};
      const running = !!scan.running;
      const processed = Number(scan.processed || 0);
      const total = Number(scan.total || snapshot.total || 0);
      const percent = Number(scan.percent || 0);

      if (progressLabel) {
        if (running) {
          progressLabel.textContent = `${adminLabels.dashboardScanProgress || 'Progression'} : ${processed} / ${total} ${adminLabels.contents || 'contenus'}`;
        } else if (Number(snapshot.analyzed_count || 0) > 0) {
          progressLabel.textContent = `${snapshot.analyzed_count} ${adminLabels.contents || 'contenus'} analysés dans le dernier snapshot.`;
        } else {
          progressLabel.textContent = 'Lancez un scan pour calculer les scores sur un maximum de 500 articles.';
        }
      }

      if (progressBadge) {
        progressBadge.textContent = running
          ? `${percent}%`
          : (Number(snapshot.analyzed_count || 0) > 0
            ? (adminLabels.dashboardScanDone || 'Analyse terminée')
            : (adminLabels.dashboardScanIdle || 'Aucun scan en cours'));
      }

      if (progressFill) {
        progressFill.style.width = `${running ? percent : (Number(snapshot.analyzed_count || 0) > 0 ? 100 : 0)}%`;
      }

      if (averageCard) {
        const avg = Number(snapshot.average_score || 0);
        averageCard.innerHTML = `${avg}<span>/100</span>`;
      }

      if (analyzedCount) {
        analyzedCount.textContent = String(Number(snapshot.analyzed_count || 0));
      }

      if (policyRiskCard) {
        policyRiskCard.textContent = String(Number(snapshot.policy_risk_count || 0));
      }

      if (clickbaitCard) {
        clickbaitCard.textContent = String(Number(snapshot.clickbait_count || 0));
      }

      renderClickbaitList(snapshot.clickbait_posts || []);

      if (scanButton) {
        scanButton.disabled = running;
        scanButton.classList.toggle('is-busy', running);
        scanButton.textContent = running
          ? (adminLabels.dashboardScanRunning || 'Analyse en cours...')
          : (adminLabels.dashboardScanStart || 'Lancer l analyse des contenus');
      }
    };

    const fetchDashboardStatus = async () => {
      const response = await window.fetch(`${window.discoopsAiAdmin.restUrl}/dashboard/scan-status`, {
        credentials: 'same-origin',
        headers: { 'X-WP-Nonce': window.discoopsAiAdmin.restNonce || '' }
      });
      if (!response.ok) {
        return null;
      }
      return response.json();
    };

    const runDashboardBatch = async (reset) => {
      const response = await window.fetch(`${window.discoopsAiAdmin.restUrl}/dashboard/scan-run`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': window.discoopsAiAdmin.restNonce || ''
        },
        body: JSON.stringify({ limit: 25, reset: !!reset })
      });
      if (!response.ok) {
        return null;
      }
      return response.json();
    };

    const loopDashboardScan = async (reset) => {
      let payload = await runDashboardBatch(reset);
      if (!payload) {
        return;
      }
      renderDashboardScan(payload);
      while (payload && payload.scan && payload.scan.running) {
        payload = await runDashboardBatch(false);
        if (!payload) {
          break;
        }
        renderDashboardScan(payload);
      }
    };

    fetchDashboardStatus()
      .then((payload) => {
        if (payload) {
          renderDashboardScan(payload);
        }
      })
      .catch(() => {});

    if (scanButton) {
      scanButton.addEventListener('click', (event) => {
        event.preventDefault();
        loopDashboardScan(true);
      });
    }

    if (clickbaitToggle && clickbaitListWrap) {
      clickbaitToggle.addEventListener('click', (event) => {
        event.preventDefault();
        const nextHidden = !clickbaitListWrap.hidden;
        clickbaitListWrap.hidden = nextHidden;
        clickbaitToggle.textContent = nextHidden
          ? (adminLabels.viewArticles || 'Voir les articles')
          : (adminLabels.hideArticles || 'Masquer les articles');
      });
    }
  }

  const root = document.querySelector('[data-discoops-editor]');
  if (!root) {
    return;
  }

  const pinMetaboxAboveYoast = () => {
    const metabox = root.closest('.postbox');
    const yoast = document.getElementById('wpseo_meta');
    if (!metabox || !yoast || !yoast.parentNode || metabox === yoast) {
      return;
    }

    if (metabox.parentNode === yoast.parentNode && metabox.nextElementSibling !== yoast) {
      yoast.parentNode.insertBefore(metabox, yoast);
    }
  };

  pinMetaboxAboveYoast();
  window.setTimeout(pinMetaboxAboveYoast, 300);
  window.setTimeout(pinMetaboxAboveYoast, 1200);
  window.setTimeout(pinMetaboxAboveYoast, 2500);

  if (window.MutationObserver) {
    const observer = new MutationObserver(() => {
      pinMetaboxAboveYoast();
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }

  const labels = (window.discoopsAiEditor && window.discoopsAiEditor.labels) || {};
  const editorUiLang = String((window.discoopsAiEditor && window.discoopsAiEditor.uiLang) || document.documentElement.lang || '');
  const isEnglishUi = /^en\b/i.test(editorUiLang);
  const looksFrench = (value) => {
    const text = String(value || '');
    return /[àâçéèêëîïôùûü]|(Très|Tres|Bien|Intertitres|Liens|Ajouter|Approfondir|Humaniser|Préparation|Preparation|Terminé|Termine|Échoué|Echoue|Mis à jour|Mis a jour|Paragraphes|Longueur|Rythme|Titres?|Bloc|Intertitre|Concret|Aucun|Voir|Masquer|Modifier|Priorité|Priorite|Signal|Commentaires?|Vérification|Verification|contenus|en file|en cours|à travailler|a travailler|bien en place)/i.test(text);
  };
  const englishUiLabels = {
    scoreLabel: 'Live audit score',
    scoreStrong: 'Strong',
    scoreAverage: 'Needs work',
    contentLength: 'Content length',
    headings: 'Headings',
    excerpt: 'Excerpt',
    focusKeyword: 'Primary keyword',
    seoTitle: 'SEO title',
    seoDescription: 'Meta description',
    internalLinks: 'Internal links',
    lists: 'Lists / FAQ',
    missing: 'Needs work',
    good: 'In place',
    great: 'Very good',
    wordUnit: 'words',
    charUnit: 'characters',
    sectionsHint: 'Add more clear sections',
    excerptHint: 'Aim for a short but complete excerpt',
    focusHint: 'Add a primary keyword',
    seoTitleHint: 'Add an SEO title',
    seoDescriptionHint: 'Add a meta description',
    linksHint: 'Add a few internal links',
    listsHint: 'Add a list or FAQ',
    structureReady: 'Enhanced structure',
    linksFound: 'links found',
    sectionsFound: 'headings',
    usefulParagraphs: 'useful paragraphs',
    jobRunning: 'Running',
    jobFailed: 'Failed',
    jobDone: 'Completed',
    jobQueued: 'Queued',
    inlineImprove: 'Deepen',
    inlineExample: 'Add an example',
    inlineHumanize: 'Humanize',
    inlineOk: 'OK',
    insertInternalLinks: 'Add links automatically',
    internalLinksInserted: 'Internal links were added automatically to the content.',
    internalLinksInsertFailed: 'Unable to add internal links automatically.',
    internalLinksUpgrade: 'Upgrade to Growth',
    paragraphs: 'Paragraphs',
    editorialRhythm: 'Editorial rhythm',
    discoverTitle: 'Discover title',
    needsMoreMaterial: 'The text needs more material',
    variedOpenings: 'The text varies its openings well',
    varyOpenings: 'Vary openings and transitions more',
    clearDiscoverTitle: 'Clear and distinctive title',
    avoidClickbaitTitle: 'Avoid titles that are too generic or sensationalist',
    policyGoodHint: 'The content remains aligned with helpful and anti-spam signals',
    policyWarnHint: 'Strengthen people-first signals and remove templated or clickbait markers',
    ready: 'Ready',
    inactive: 'Inactive',
    blockSolidDetailed: 'Solid and detailed block',
    blockTooShort: 'Block too short: add a practical consequence or an example',
    blockTooAbstract: 'Block still feels abstract: add an example or a concrete action',
    blockTooAi: 'Readable block, but still too close to recurring AI phrasing',
    headingTooShort: 'Heading is a bit short: make it more specific or informative',
    headingCouldPromiseMore: 'Decent heading, but it could signal the section promise more clearly',
    headingGood: 'Clear, useful heading that introduces the section well',
    blockLabel: 'Block',
    headingLabel: 'Heading',
    textLabel: 'Text',
    alreadyDetailed: 'Main paragraphs already have a good level of detail',
    richEnough: 'In place',
    enrichBlock: 'Needs enrichment',
    makeMoreConcrete: 'More concrete',
    tooShortParagraph: 'Too short: add a concrete example or a practical outcome',
    makeParagraphConcrete: 'Add a why, a concrete action or a visible outcome',
    viewArticles: 'View articles',
    hideArticles: 'Hide articles',
    noClickbaitArticles: 'No clickbait article in the latest snapshot.',
    edit: 'Edit',
    view: 'View',
    ready: 'Ready',
    inactive: 'Inactive',
    contents: 'contents',
    jobType: 'Type',
    priority: 'Priority',
    updatedAt: 'Updated at'
  };
  if (isEnglishUi) {
    Object.keys(englishUiLabels).forEach((key) => {
      if (!labels[key] || looksFrench(labels[key])) {
        labels[key] = englishUiLabels[key];
      }
    });
  }
  const canRework = !!(window.discoopsAiEditor && window.discoopsAiEditor.canRework);
  const canAutoInternalLinks = !!(window.discoopsAiEditor && window.discoopsAiEditor.canAutoInternalLinks);
  const scoreValueEl = root.querySelector('[data-discoops-score-value]');
  const scoreStatusEl = root.querySelector('[data-discoops-score-status]');
  const scoreRingEl = root.querySelector('[data-discoops-score-ring]');
  const checksEl = root.querySelector('[data-discoops-live-checks]');
  const inlineSuggestionsEl = root.querySelector('[data-discoops-inline-suggestions]');
  const blockScoresEl = root.querySelector('[data-discoops-block-scores]');
  const jobStatusEl = root.querySelector('[data-discoops-job-status]');
  const commentTextarea = root.querySelector('textarea[name="discoops_editor_comment_message"]');
  const autoLinksButton = root.querySelector('[data-discoops-auto-links]');
  const initialAudit = Number(root.getAttribute('data-initial-audit') || '0');
  const lastJobId = Number(root.getAttribute('data-last-job-id') || '0');
  const postId = Number(root.getAttribute('data-post-id') || '0');
  const internalLinkSuggestions = (() => {
    try {
      const raw = root.getAttribute('data-internal-link-suggestions') || '[]';
      const parsed = JSON.parse(raw);
      return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
      return [];
    }
  })();

  const editorSelect = () => {
    if (!window.wp || !window.wp.data || !window.wp.data.select) {
      return null;
    }

    return window.wp.data.select('core/editor');
  };

  const blockEditorSelect = () => {
    if (!window.wp || !window.wp.data || !window.wp.data.select) {
      return null;
    }

    return window.wp.data.select('core/block-editor');
  };

  const blockEditorDispatch = () => {
    if (!window.wp || !window.wp.data || !window.wp.data.dispatch) {
      return null;
    }

    return window.wp.data.dispatch('core/block-editor');
  };

  const editorDispatch = () => {
    if (!window.wp || !window.wp.data || !window.wp.data.dispatch) {
      return null;
    }

    return window.wp.data.dispatch('core/editor');
  };

  const getEditorState = () => {
    const editorStore = editorSelect();
    const meta = editorStore && editorStore.getEditedPostAttribute ? (editorStore.getEditedPostAttribute('meta') || {}) : {};
    const content = editorStore && editorStore.getEditedPostAttribute ? String(editorStore.getEditedPostAttribute('content') || '') : '';
    const excerpt = editorStore && editorStore.getEditedPostAttribute ? String(editorStore.getEditedPostAttribute('excerpt') || '') : '';
    const title = editorStore && editorStore.getEditedPostAttribute ? String(editorStore.getEditedPostAttribute('title') || '') : '';
    const domValue = (selectors) => {
      for (const selector of selectors) {
        const node = document.querySelector(selector);
        if (!node) {
          continue;
        }
        const value = 'value' in node ? String(node.value || '') : String(node.textContent || '');
        if (value.trim() !== '') {
          return value.trim();
        }
      }
      return '';
    };

    const focusKeyword =
      String(meta._discoops_focus_keyword || '').trim() ||
      String(meta._yoast_wpseo_focuskw || '').trim() ||
      String(meta.rank_math_focus_keyword || '').trim() ||
      domValue([
        'input[name="yoast_wpseo_focuskw"]',
        '#focus-keyword-input-metabox',
        'input[name="rank_math_focus_keyword"]',
        'input[data-rmfield="focus_keyword"]'
      ]);

    const seoTitle =
      String(meta._discoops_seo_title || '').trim() ||
      String(meta._yoast_wpseo_title || '').trim() ||
      String(meta.rank_math_title || '').trim() ||
      domValue([
        'input[name="yoast_wpseo_title"]',
        '#yoast-google-preview-title-metabox',
        'input[name="rank_math_title"]',
        'textarea[name="rank_math_title"]'
      ]);

    const seoDescription =
      String(meta._discoops_seo_description || '').trim() ||
      String(meta._yoast_wpseo_metadesc || '').trim() ||
      String(meta.rank_math_description || '').trim() ||
      domValue([
        'textarea[name="yoast_wpseo_metadesc"]',
        '#yoast-google-preview-description-metabox',
        'textarea[name="rank_math_description"]'
      ]);

    return {
      title,
      content,
      excerpt,
      focusKeyword,
      seoTitle,
      seoDescription
    };
  };

  const stripHtml = (html) => {
    const div = document.createElement('div');
    div.innerHTML = html;
    return (div.textContent || div.innerText || '').replace(/\s+/g, ' ').trim();
  };

  const normalizeForAnalysis = (value) => String(value || '')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[’']/g, "'")
    .toLowerCase();

  const scoreLabel = (score) => {
    if (score >= 85) return labels.scoreExcellent || 'Excellent';
    if (score >= 70) return labels.scoreStrong || 'Solide';
    if (score >= 55) return labels.scoreAverage || 'A renforcer';
    return labels.scoreWeak || 'Fragile';
  };

  const describeCount = (count, unit) => `${count} ${unit}`;

  const evaluate = () => {
    const state = getEditorState();
    const text = stripHtml(state.content);
    const normalizedText = normalizeForAnalysis(text);
    const wordCount = text ? text.split(/\s+/).filter(Boolean).length : 0;
    const headingCount = (state.content.match(/<h2\b/gi) || []).length + (state.content.match(/<h3\b/gi) || []).length;
    const internalLinkCount = (state.content.match(/<a\s[^>]*href=/gi) || []).length;
    const classicEditorLinks = document.querySelectorAll('#content a[href], .editor-styles-wrapper a[href]').length;
    const listCount = (state.content.match(/<(ul|ol)\b/gi) || []).length;
    const excerptLength = state.excerpt.trim().length;
    const titleLength = state.title.trim().length;
    const repeatedOpenings = (normalizedText.match(/\b(ces|cette|dans cette|quand on|envie de|le vrai point|le detail qui)\b/g) || []).length;
    const paragraphCount = (state.content.match(/<p\b/gi) || []).length;
    const normalizedTitle = normalizeForAnalysis(state.title);
    const titleLooksClicky = /\b(incroyable|secret|carton|hallucinant|ultra|magique|qui change tout|indecent)\b/.test(normalizedTitle);
    const titleLooksGeneric = /\b(recette facile|astuce simple|plat facile|dessert gourmand)\b/.test(normalizedTitle);
    const hasSpamMarkers = /\[\[\[agp_|image illustration|prompt|chatgpt|generated by ai/.test(normalizedText);
    const hasPolicyPracticalSignals = /\b(en pratique|par exemple|si|quand|pour eviter|resultat|texture|cuisson|au moment de servir|geste|detail|erreur|comparaison)\b/.test(normalizedText);

    const checks = [
      {
        key: 'content',
        title: labels.contentLength || 'Longueur de contenu',
        value: wordCount >= 900 ? 'great' : (wordCount >= 650 ? 'good' : 'warn'),
        hint: wordCount >= 900
          ? describeCount(wordCount, labels.wordUnit || 'mots')
          : (wordCount >= 650
            ? describeCount(wordCount, labels.wordUnit || 'mots')
            : `${describeCount(wordCount, labels.wordUnit || 'mots')}, encore un peu court`)
      },
      {
        key: 'headings',
        title: labels.headings || 'Intertitres',
        value: headingCount >= 4 ? 'great' : (headingCount >= 2 ? 'good' : 'warn'),
        hint: headingCount >= 2
          ? describeCount(headingCount, labels.sectionsFound || 'intertitres')
          : (labels.sectionsHint || 'Ajoutez plus de sections claires')
      },
      {
        key: 'excerpt',
        title: labels.excerpt || 'Extrait',
        value: excerptLength >= 120 && excerptLength <= 220 ? 'good' : 'warn',
        hint: excerptLength >= 120 && excerptLength <= 220
          ? describeCount(excerptLength, labels.charUnit || 'caracteres')
          : (labels.excerptHint || 'Visez un extrait court mais complet')
      },
      {
        key: 'focus',
        title: labels.focusKeyword || 'Mot-cle principal',
        value: state.focusKeyword.trim() !== '' ? 'good' : 'warn',
        hint: state.focusKeyword.trim() !== '' ? state.focusKeyword.trim() : (labels.focusHint || 'Ajoutez un mot-cle principal')
      },
      {
        key: 'seoTitle',
        title: labels.seoTitle || 'Titre SEO',
        value: state.seoTitle.trim().length >= 35 ? 'good' : 'warn',
        hint: state.seoTitle.trim() !== ''
          ? describeCount(state.seoTitle.trim().length, labels.charUnit || 'caracteres')
          : (labels.seoTitleHint || 'Ajoutez un titre SEO')
      },
      {
        key: 'seoDescription',
        title: labels.seoDescription || 'Meta description',
        value: state.seoDescription.trim().length >= 90 ? 'good' : 'warn',
        hint: state.seoDescription.trim() !== ''
          ? describeCount(state.seoDescription.trim().length, labels.charUnit || 'caracteres')
          : (labels.seoDescriptionHint || 'Ajoutez une meta description')
      },
      {
        key: 'links',
        title: labels.internalLinks || 'Liens internes',
        value: Math.max(internalLinkCount, classicEditorLinks) >= 3 ? 'great' : (Math.max(internalLinkCount, classicEditorLinks) >= 1 ? 'good' : 'warn'),
        hint: Math.max(internalLinkCount, classicEditorLinks) >= 1
          ? describeCount(Math.max(internalLinkCount, classicEditorLinks), labels.linksFound || 'liens trouves')
          : (labels.linksHint || 'Ajoutez quelques liens internes')
      },
      {
        key: 'lists',
        title: labels.lists || 'Listes / FAQ',
        value: listCount >= 1 ? 'good' : 'warn',
        hint: listCount >= 1
          ? (labels.structureReady || 'Structure enrichie')
          : (labels.listsHint || 'Ajoutez une liste ou une FAQ')
      },
      {
        key: 'paragraphs',
        title: labels.paragraphs || 'Paragraphes',
        value: paragraphCount >= 6 ? 'good' : 'warn',
        hint: paragraphCount >= 6
          ? `${paragraphCount} ${labels.usefulParagraphs || 'useful paragraphs'}`
          : (labels.needsMoreMaterial || 'Le texte a besoin de plus de matière')
      },
      {
        key: 'rhythm',
        title: labels.editorialRhythm || 'Rythme éditorial',
        value: repeatedOpenings <= 6 && titleLength >= 45 ? 'good' : 'warn',
        hint: repeatedOpenings <= 6
          ? (labels.variedOpenings || 'Le texte varie bien ses ouvertures')
          : (labels.varyOpenings || 'Variez davantage les ouvertures et transitions')
      },
      {
        key: 'discoverTitle',
        title: labels.discoverTitle || 'Titre Discover',
        value: !titleLooksClicky && !titleLooksGeneric ? 'good' : 'warn',
        hint: !titleLooksClicky && !titleLooksGeneric
          ? (labels.clearDiscoverTitle || 'Titre clair et assez distinctif')
          : (labels.avoidClickbaitTitle || 'Évitez le titre trop générique ou trop sensationnaliste')
      },
      {
        key: 'policies',
        title: labels.googlePolicies || 'Policies Google',
        value: !hasSpamMarkers && !titleLooksClicky && hasPolicyPracticalSignals ? 'good' : 'warn',
        hint: !hasSpamMarkers && !titleLooksClicky && hasPolicyPracticalSignals
          ? (labels.policyGoodHint || 'Le contenu reste globalement cohérent avec les signaux helpful et anti-spam')
          : (labels.policyWarnHint || 'Renforcez les signaux people-first et retirez tout marqueur trop template ou trop clickbait')
      }
    ];

    const liveBonus = checks.reduce((sum, check) => {
      if (check.value === 'great') return sum + 8;
      if (check.value === 'good') return sum + 5;
      return sum - 3;
    }, 0);

    const score = Math.max(0, Math.min(100, Math.round((initialAudit * 0.55) + 30 + liveBonus)));
    const paragraphSuggestions = [];
    const paragraphs = state.content
      .split(/<\/p>/i)
      .map((entry) => stripHtml(entry))
      .filter(Boolean)
      .filter((entry) => entry.length > 40);

    paragraphs.slice(0, 8).forEach((paragraph, index) => {
      const normalizedParagraph = normalizeForAnalysis(paragraph);
      const hasConcreteSignal = /\b(parce que|pour eviter|en pratique|si|quand|resultat|texture|cuisson|par exemple|par ex|permet de|servi|servie|preparee|prepare|accompagne|accompagnee|avec une|avec un|a table|avant de|au moment|tout de suite|facile a partager|pratique)\b/.test(normalizedParagraph);
      const hasExampleSignal = /\b(par exemple|comme lorsque|si par exemple|accompagnee? de|servi[ea]? avec|avec une salade|avec un accompagnement)\b/.test(normalizedParagraph);
      if (paragraph.length < 95) {
        paragraphSuggestions.push({
          title: `${labels.blockLabel || 'Bloc'} ${index + 1}`,
          hint: labels.tooShortParagraph || 'Trop court : ajoutez un exemple concret ou une conséquence pratique',
          state: 'warn',
          badge: labels.enrichBlock || 'A enrichir'
        });
      } else if (!hasConcreteSignal && !hasExampleSignal) {
        paragraphSuggestions.push({
          title: `${labels.blockLabel || 'Bloc'} ${index + 1}`,
          hint: labels.makeParagraphConcrete || 'Ajoutez un pourquoi, un geste concret ou une conséquence visible',
          state: 'warn',
          badge: labels.makeMoreConcrete || 'Plus concret'
        });
      }
    });

    const blockScores = paragraphs.slice(0, 8).map((paragraph, index) => {
      const normalizedParagraph = normalizeForAnalysis(paragraph);
      const hasConcreteSignal = /\b(parce que|pour eviter|en pratique|si|quand|resultat|texture|cuisson|par exemple|par ex|permet de|servi|servie|preparee|prepare|accompagne|accompagnee|avec une|avec un|a table|avant de|au moment|tout de suite|facile a partager|pratique)\b/.test(normalizedParagraph);
      let score = 82;
      let hint = labels.blockSolidDetailed || 'Bloc solide et assez détaillé';
      let action = labels.inlineOk || 'RAS';

      if (paragraph.length < 95) {
        score = 46;
        hint = labels.blockTooShort || 'Bloc trop court : ajoutez une conséquence pratique ou un exemple';
        action = labels.inlineImprove || 'Approfondir';
      } else if (!hasConcreteSignal) {
        score = 61;
        hint = labels.blockTooAbstract || 'Bloc encore un peu abstrait : ajoutez un exemple ou un geste concret';
        action = labels.inlineExample || 'Ajouter un exemple';
      } else if (/\b(imaginez|en conclusion|par ailleurs|de plus|le vrai point)\b/.test(normalizedParagraph)) {
        score = 58;
        hint = labels.blockTooAi || 'Bloc lisible, mais trop proche de formulations IA récurrentes';
        action = labels.inlineHumanize || 'Humaniser';
      }

      return {
        title: `${labels.blockLabel || 'Bloc'} ${index + 1}`,
        score,
        hint,
        state: score >= 75 ? 'good' : 'warn',
        action
      };
    });

    if (!paragraphSuggestions.length) {
      paragraphSuggestions.push({
        title: labels.textLabel || 'Texte',
        hint: labels.alreadyDetailed || 'Les paragraphes principaux ont déjà un niveau de détail correct',
        state: 'good',
        badge: labels.richEnough || 'Bien en place'
      });
    }

    return { score, checks, paragraphSuggestions, blockScores };
  };

  const getEditorBlocks = () => {
    const blockStore = blockEditorSelect();
    if (!blockStore || !blockStore.getBlocks) {
      return [];
    }
    return blockStore.getBlocks() || [];
  };

  const buildBlockInsights = () => {
    const blocks = getEditorBlocks();
    const supported = [];

    blocks.forEach((block, index) => {
      const name = String(block.name || '');
      if (!['core/paragraph', 'core/heading', 'core/list', 'core/quote'].includes(name)) {
        return;
      }

      const rawText = stripHtml(String((block.attributes && (block.attributes.content || block.attributes.value)) || ''));
      const normalizedRawText = normalizeForAnalysis(rawText);
      if (!rawText) {
        return;
      }

      let score = 84;
      let hint = labels.blockSolidDetailed || 'Bloc solide et bien rythmé';
      let action = labels.inlineOk || 'RAS';
      let state = 'good';

      if (name === 'core/heading') {
        if (rawText.length < 18) {
          score = 62;
          hint = labels.headingTooShort || 'Intertitre un peu court : rendez-le plus spécifique ou plus informatif';
          action = labels.inlineImprove || 'Approfondir';
          state = 'warn';
        } else if (!/\b(comment|pourquoi|quelle|quels|astuce|erreur|conseil|detail|geste|texture|cuisson|service|resultat|ingredients?)\b/.test(normalizedRawText)) {
          score = 72;
          hint = labels.headingCouldPromiseMore || 'Intertitre correct, mais il peut annoncer plus clairement la promesse de la section';
          action = labels.inlineImprove || 'Approfondir';
          state = 'good';
        } else {
          score = 88;
          hint = labels.headingGood || 'Intertitre clair, utile et bien calibré pour introduire la section';
          action = labels.inlineOk || 'RAS';
          state = 'good';
        }

        supported.push({
          clientId: String(block.clientId || ''),
          index: index + 1,
          title: `${labels.headingLabel || 'Intertitre'} ${index + 1}`,
          score,
          hint,
          action,
          state
        });
        return;
      }

      const hasConcreteSignal = /\b(parce que|pour eviter|en pratique|si|quand|resultat|texture|cuisson|par exemple|par ex|permet de|servi|servie|preparee|prepare|accompagne|accompagnee|avec une|avec un|a table|avant de|au moment|tout de suite|facile a partager|pratique)\b/.test(normalizedRawText);

      if (rawText.length < 90) {
        score = 48;
        hint = labels.tooShortParagraph || 'Bloc trop court : ajoutez un détail concret ou une conséquence utile';
        action = labels.inlineImprove || 'Approfondir';
        state = 'warn';
      } else if (!hasConcreteSignal) {
        score = 61;
        hint = labels.makeParagraphConcrete || 'Ajoutez un pourquoi, un geste concret ou une conséquence visible';
        action = labels.inlineExample || 'Ajouter un exemple';
        state = 'warn';
      } else if (/\b(imaginez|en conclusion|par ailleurs|de plus|le vrai point)\b/.test(normalizedRawText)) {
        score = 57;
        hint = labels.blockTooAi || 'Bloc lisible, mais trop proche de formulations IA récurrentes';
        action = labels.inlineHumanize || 'Humaniser';
        state = 'warn';
      }

      supported.push({
        clientId: String(block.clientId || ''),
        index: index + 1,
        title: name === 'core/heading'
          ? `${labels.headingLabel || 'Intertitre'} ${index + 1}`
          : `${labels.blockLabel || 'Bloc'} ${index + 1}`,
        score,
        hint,
        action,
        state
      });
    });

    return supported;
  };

  const renderChecks = (checks) => {
    if (!checksEl) {
      return;
    }

    checksEl.innerHTML = checks.map((check) => `
      <div class="discoops-ai-live-check is-${check.value}">
        <div class="discoops-ai-live-check__meta">
          <div class="discoops-ai-live-check__title">${check.title}</div>
          <div class="discoops-ai-live-check__hint">${check.hint}</div>
        </div>
        <div class="discoops-ai-live-check__badge">
          ${check.value === 'great'
            ? (labels.great || 'Tres bon niveau')
            : (check.value === 'good'
              ? (labels.good || 'Bien en place')
              : (labels.missing || 'A travailler'))}
        </div>
      </div>
    `).join('');
    blockScoresEl.innerHTML = blockScoresEl.innerHTML.replace(/\u00C2\u00B7/g, '-');
  };

  const renderScore = (score) => {
    if (scoreValueEl) {
      scoreValueEl.textContent = String(score);
    }
    if (scoreStatusEl) {
      scoreStatusEl.textContent = scoreLabel(score);
      scoreStatusEl.classList.remove('is-excellent', 'is-strong', 'is-average', 'is-weak');
      if (score >= 85) {
        scoreStatusEl.classList.add('is-excellent');
      } else if (score >= 70) {
        scoreStatusEl.classList.add('is-strong');
      } else if (score >= 55) {
        scoreStatusEl.classList.add('is-average');
      } else {
        scoreStatusEl.classList.add('is-weak');
      }
    }
    if (scoreRingEl) {
      scoreRingEl.style.setProperty('--score-fill', String(score));
      if (score >= 85) {
        scoreRingEl.style.setProperty('--score-color', '#14c38e');
      } else if (score >= 70) {
        scoreRingEl.style.setProperty('--score-color', '#2563eb');
      } else if (score >= 55) {
        scoreRingEl.style.setProperty('--score-color', '#f59e0b');
      } else {
        scoreRingEl.style.setProperty('--score-color', '#ef4444');
      }
    }
  };

  const renderInlineSuggestions = (items) => {
    if (!inlineSuggestionsEl) {
      return;
    }

    inlineSuggestionsEl.innerHTML = items.map((item) => `
      <div class="discoops-ai-live-check is-${item.state}">
        <div class="discoops-ai-live-check__meta">
          <div class="discoops-ai-live-check__title">${item.title}</div>
          <div class="discoops-ai-live-check__hint">${item.hint}</div>
        </div>
        <div class="discoops-ai-live-check__badge">${item.badge}</div>
      </div>
    `).join('');
    blockScoresEl.innerHTML = blockScoresEl.innerHTML.replace(/\u00C2\u00B7/g, '-');
  };

  const renderBlockScores = (items) => {
    if (!blockScoresEl) {
      return;
    }

    blockScoresEl.innerHTML = items.map((item) => `
      <div class="discoops-ai-live-check is-${item.state}">
        <div class="discoops-ai-live-check__meta">
          <div class="discoops-ai-live-check__title">${item.title} - ${item.score}/100</div>
          <div class="discoops-ai-live-check__hint">${item.hint}</div>
        </div>
        <div class="discoops-ai-live-check__badge">${item.action}</div>
      </div>
    `).join('');
  };

  const cleanupInlineUi = () => {
    document.querySelectorAll('.discoops-ai-inline-badge').forEach((node) => node.remove());
    document.querySelectorAll('.discoops-ai-has-inline-badge').forEach((node) => {
      node.classList.remove('discoops-ai-has-inline-badge');
    });
  };

  const renderInlineBlockBadges = (items) => {
    cleanupInlineUi();
    if (!items.length || !canRework) {
      return;
    }

    items.forEach((item) => {
      if (!item.clientId) {
        return;
      }
      const blockNode = document.querySelector(`[data-block="${item.clientId}"]`);
      if (!blockNode) {
        return;
      }

      blockNode.classList.add('discoops-ai-has-inline-badge');

      const badge = document.createElement('div');
      badge.className = `discoops-ai-inline-badge is-${item.state}`;
      badge.setAttribute('role', 'button');
      badge.setAttribute('tabindex', '0');
      badge.dataset.discoopsAction = item.action;
      badge.dataset.discoopsHint = item.hint;
      badge.dataset.discoopsClientId = item.clientId;
      badge.dataset.discoopsInlineUi = '1';
      badge.innerHTML = `
        <span class="discoops-ai-inline-badge__score">${item.score}</span>
        <span class="discoops-ai-inline-badge__text">${item.action}</span>
      `;
      if (blockNode.parentNode) {
        blockNode.parentNode.insertBefore(badge, blockNode);
      }
    });
  };

  const handleInlineBadgeIntent = (badge) => {
    if (!badge || !commentTextarea) {
      return;
    }

    const action = String(badge.dataset.discoopsAction || '').trim();
    const hint = String(badge.dataset.discoopsHint || '').trim();
    if (!action || !hint) {
      return;
    }

    const prefix = commentTextarea.value.trim() !== '' ? '\n' : '';
    commentTextarea.value = `${commentTextarea.value}${prefix}[Suggestion inline] ${action} - ${hint}`.trim();
    commentTextarea.focus();
    commentTextarea.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };

  const appendInlineSuggestionComment = (action, hint) => {
    if (!commentTextarea || !action || !hint) {
      return;
    }

    const prefix = commentTextarea.value.trim() !== '' ? '\n' : '';
    commentTextarea.value = `${commentTextarea.value}${prefix}[Suggestion inline] ${action} - ${hint}`.trim();
    commentTextarea.focus();
    commentTextarea.scrollIntoView({ behavior: 'smooth', block: 'center' });
  };

  const syncEditorContent = (html) => {
    const nextHtml = String(html || '').trim();
    if (!nextHtml) {
      return;
    }

    const editorDispatcher = editorDispatch();
    if (editorDispatcher && editorDispatcher.editPost) {
      editorDispatcher.editPost({ content: nextHtml });
    }

    if (window.wp && window.wp.blocks && window.wp.blocks.parse) {
      const parsedBlocks = window.wp.blocks.parse(nextHtml);
      const blockDispatcher = blockEditorDispatch();
      if (blockDispatcher && blockDispatcher.resetBlocks && Array.isArray(parsedBlocks)) {
        blockDispatcher.resetBlocks(parsedBlocks);
      }
    }
  };

  const getBlockAttributeKey = (blockName) => {
    if (blockName === 'core/quote') {
      return 'value';
    }

    if (blockName === 'core/paragraph' || blockName === 'core/heading') {
      return 'content';
    }

    return '';
  };

  const rewriteInlineBlock = async (badge) => {
    if (!badge || !canRework) {
      return;
    }

    const action = String(badge.dataset.discoopsAction || '').trim();
    const hint = String(badge.dataset.discoopsHint || '').trim();
    const clientId = String(badge.dataset.discoopsClientId || '').trim();
    if (!action || !hint || !clientId || !postId || !window.fetch || !window.discoopsAiEditor) {
      appendInlineSuggestionComment(action, hint);
      return;
    }

    const blocks = getEditorBlocks();
    const block = blocks.find((entry) => String(entry.clientId || '') === clientId);
    if (!block) {
      appendInlineSuggestionComment(action, hint);
      return;
    }

    const blockName = String(block.name || '');
    const attributeKey = getBlockAttributeKey(blockName);
    if (!attributeKey) {
      appendInlineSuggestionComment(action, hint);
      return;
    }

    const currentContent = String((block.attributes && block.attributes[attributeKey]) || '').trim();
    if (!currentContent) {
      appendInlineSuggestionComment(action, hint);
      return;
    }

    badge.classList.add('is-loading');

    try {
      const response = await window.fetch(`${window.discoopsAiEditor.restUrl}/editor/rewrite-block`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': window.discoopsAiEditor.restNonce || ''
        },
        body: JSON.stringify({
          post_id: postId,
          client_id: clientId,
          block_name: blockName,
          action,
          content: currentContent
        })
      });

      const data = response.ok ? await response.json() : null;
      const rewritten = data && typeof data.rewritten === 'string' ? data.rewritten.trim() : '';
      if (!rewritten || !window.wp || !window.wp.data || !window.wp.data.dispatch) {
        appendInlineSuggestionComment(action, hint);
        return;
      }

      window.wp.data.dispatch('core/block-editor').updateBlockAttributes(clientId, {
        [attributeKey]: rewritten
      });

        appendInlineSuggestionComment(
          action,
          typeof data.note === 'string' && data.note.trim() !== '' ? data.note.trim() : hint
        );
        window.setTimeout(refresh, 120);
        window.setTimeout(refresh, 500);
      } catch (error) {
        appendInlineSuggestionComment(action, hint);
      } finally {
      badge.classList.remove('is-loading');
    }
  };

  const autoInsertInternalLinks = async () => {
    if (!autoLinksButton || !postId || !internalLinkSuggestions.length || !window.fetch || !window.discoopsAiEditor) {
      return;
    }

    const state = getEditorState();
    if (!state.content.trim()) {
      return;
    }

    autoLinksButton.disabled = true;
    autoLinksButton.classList.add('is-busy');

    try {
      const response = await window.fetch(`${window.discoopsAiEditor.restUrl}/posts/add-internal-links`, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': window.discoopsAiEditor.restNonce || ''
        },
        body: JSON.stringify({
          id: postId,
          links: internalLinkSuggestions,
          content: state.content
        })
      });

      const data = response.ok ? await response.json() : null;
      const updatedContent = data && typeof data.content === 'string' ? data.content : '';
      const insertedCount = data && typeof data.inserted_count === 'number' ? data.inserted_count : 0;
      const changed = !!(data && data.changed);
      if (updatedContent && changed) {
        syncEditorContent(updatedContent);
        if (commentTextarea) {
          const prefix = commentTextarea.value.trim() !== '' ? '\n' : '';
          const successMessage = insertedCount > 0
            ? `${insertedCount} lien(s) interne(s) ajoutés automatiquement dans le contenu.`
            : (labels.internalLinksInserted || 'Liens internes ajoutes automatiquement dans le contenu.');
          commentTextarea.value = `${commentTextarea.value}${prefix}${successMessage}`.trim();
        }
        window.setTimeout(refresh, 180);
        window.setTimeout(refresh, 700);
      } else if (commentTextarea) {
        const prefix = commentTextarea.value.trim() !== '' ? '\n' : '';
        commentTextarea.value = `${commentTextarea.value}${prefix}${labels.internalLinksInsertFailed || 'Unable to add internal links automatically.'}`.trim();
      }
    } catch (error) {
      if (commentTextarea) {
        const prefix = commentTextarea.value.trim() !== '' ? '\n' : '';
        commentTextarea.value = `${commentTextarea.value}${prefix}${labels.internalLinksInsertFailed || 'Unable to add internal links automatically.'}`.trim();
      }
    } finally {
      autoLinksButton.disabled = false;
      autoLinksButton.classList.remove('is-busy');
    }
  };

  const renderJobStatus = (job) => {
    if (!jobStatusEl) {
      return;
    }

    if (!job || !job.id) {
      return;
    }

    const status = String(job.status || '').toLowerCase();
    const isFailed = status === 'failed' || status === 'rejected';
    const isDone = status === 'approved' || status === 'done' || status === 'completed';
    const isRunning = status === 'running' || status === 'in_review';
    const state = isFailed ? 'warn' : (isDone ? 'good' : (isRunning ? 'great' : 'good'));
    const badge = isFailed
      ? (labels.jobFailed || 'Failed')
      : (isDone ? (labels.jobDone || 'Completed') : (isRunning ? (labels.jobRunning || 'Running') : (labels.jobQueued || 'Queued')));

    jobStatusEl.innerHTML = `
      <div class="discoops-ai-live-check is-${state}">
        <div class="discoops-ai-live-check__meta">
          <div class="discoops-ai-live-check__title">Job #${job.id}</div>
          <div class="discoops-ai-live-check__hint">${labels.jobType || 'Type'}: ${job.job_type || 'n/a'} | ${labels.priority || 'Priorité'}: ${job.priority || 'n/a'} | ${labels.updatedAt || 'Mis à jour'}: ${job.updated_at || job.created_at || 'n/a'}</div>
        </div>
        <div class="discoops-ai-live-check__badge">${badge}</div>
      </div>
    `;
  };

  const pollJobStatus = () => {
    if (!jobStatusEl || !lastJobId || !window.fetch || !window.discoopsAiEditor || !window.discoopsAiEditor.restUrl) {
      return;
    }

    const headers = { 'X-WP-Nonce': window.discoopsAiEditor.restNonce || '' };
    window.fetch(`${window.discoopsAiEditor.restUrl}/jobs/${lastJobId}`, { headers, credentials: 'same-origin' })
      .then((response) => response.ok ? response.json() : null)
      .then((job) => {
        if (job) {
          renderJobStatus(job);
        }
      })
      .catch(() => {});
  };

  const refresh = () => {
    const { score, checks, paragraphSuggestions, blockScores } = evaluate();
    const blockInsights = buildBlockInsights();
    renderScore(score);
    renderChecks(checks);
    renderInlineSuggestions(paragraphSuggestions);
    renderBlockScores(blockInsights.length ? blockInsights : blockScores);
    renderInlineBlockBadges(blockInsights);
  };

  refresh();
  pollJobStatus();
  if (lastJobId > 0) {
    window.setInterval(pollJobStatus, 12000);
  }

  document.addEventListener('click', (event) => {
    const target = event.target.closest('.discoops-ai-inline-badge');
    if (!target) {
      return;
    }
    rewriteInlineBlock(target);
  });

  document.addEventListener('keydown', (event) => {
    const target = event.target.closest('.discoops-ai-inline-badge');
    if (!target) {
      return;
    }
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      rewriteInlineBlock(target);
    }
  });

  if (autoLinksButton) {
    autoLinksButton.addEventListener('click', (event) => {
      event.preventDefault();
      if (!canAutoInternalLinks) {
        const billingUrl = window.discoopsAiEditor && window.discoopsAiEditor.billingUrl;
        if (billingUrl) {
          window.open(billingUrl, '_blank', 'noopener,noreferrer');
        }
        return;
      }
      autoInsertInternalLinks();
    });
  }

  if (window.wp && window.wp.data && window.wp.data.subscribe) {
    let lastSignature = '';
    let lastIsSaving = false;
    window.wp.data.subscribe(() => {
      pinMetaboxAboveYoast();
      const editorStore = editorSelect();
      const isSaving = !!(editorStore && editorStore.isSavingPost && editorStore.isSavingPost());
      if (isSaving && !lastIsSaving) {
        cleanupInlineUi();
      }
      if (!isSaving && lastIsSaving) {
        window.setTimeout(refresh, 200);
      }
      lastIsSaving = isSaving;
      const state = getEditorState();
      const signature = [
        state.title,
        state.content,
        state.excerpt,
        state.focusKeyword,
        state.seoTitle,
        state.seoDescription
      ].join('||');

      if (signature === lastSignature) {
        return;
      }

      lastSignature = signature;
      refresh();
    });
  } else {
    setInterval(refresh, 2500);
  }
});
