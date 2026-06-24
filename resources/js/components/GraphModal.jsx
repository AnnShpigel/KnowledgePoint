import { useEffect, useRef, useState, useCallback } from 'react';
import { X } from 'lucide-react';
import ForceGraph2D from 'react-force-graph-2d';
import { contentAPI } from '../services/api';
import { useNavigate } from 'react-router-dom';

const NODE_COLORS = {
  center:   '#4F46E5',
  tag:      '#14B8A6',
  resource: '#F59E0B',
};

const NODE_RADIUS = {
  center:   14,
  tag:       7,
  resource: 10,
};

const LEGEND = [
  { color: '#4F46E5', label: 'Текущий материал' },
  { color: '#14B8A6', label: 'Общий тег' },
  { color: '#F59E0B', label: 'Связанный материал' },
];

const GraphModal = ({ onClose, resourceId, resourceTitle }) => {
  const navigate    = useNavigate();
  const containerRef = useRef(null);
  const graphRef    = useRef(null);

  const [graphData, setGraphData] = useState({ nodes: [], links: [] });
  const [loading,   setLoading]   = useState(true);
  const [error,     setError]     = useState(null);
  const [dims,      setDims]      = useState({ width: 800, height: 500 });

  // Загрузка данных графа
  useEffect(() => {
    if (!resourceId) {
      setLoading(false);
      setError('Идентификатор материала не передан.');
      return;
    }

    contentAPI.getRelatedGraph(resourceId)
      .then(data => {
        if (!data?.success) {
          setError(data?.error ?? 'Не удалось загрузить граф.');
          return;
        }
        if (!data.available) {
          setError('Семантическое хранилище недоступно.');
          return;
        }
        if (!data.nodes?.length || data.nodes.length <= 1) {
          setError('Семантических связей не найдено.');
          return;
        }
        setGraphData({ nodes: data.nodes, links: data.links });
      })
      .catch(() => setError('Ошибка сети при запросе к API.'))
      .finally(() => setLoading(false));
  }, [resourceId]);

  // Обновление размеров контейнера
  useEffect(() => {
    const update = () => {
      if (containerRef.current) {
        const r = containerRef.current.getBoundingClientRect();
        setDims({ width: Math.floor(r.width), height: Math.floor(r.height) });
      }
    };
    update();
    const ro = new ResizeObserver(update);
    if (containerRef.current) ro.observe(containerRef.current);
    return () => ro.disconnect();
  }, []);

  const handleEngineStop = useCallback(() => {
    graphRef.current?.zoomToFit(400, 50);
  }, []);

  // Закрытие по Escape
  useEffect(() => {
    const handler = (e) => { if (e.key === 'Escape') onClose(); };
    document.addEventListener('keydown', handler);
    return () => document.removeEventListener('keydown', handler);
  }, [onClose]);

  const paintNode = useCallback((node, ctx, globalScale) => {
    const radius = NODE_RADIUS[node.type] ?? 8;
    const color  = NODE_COLORS[node.type] ?? '#94A3B8';

    // Тень для центрального узла
    if (node.type === 'center') {
      ctx.shadowColor = 'rgba(79,70,229,0.35)';
      ctx.shadowBlur  = 12 / globalScale;
    }

    ctx.beginPath();
    ctx.arc(node.x, node.y, radius, 0, 2 * Math.PI);
    ctx.fillStyle = color;
    ctx.fill();

    ctx.shadowColor = 'transparent';
    ctx.shadowBlur  = 0;

    if (node.type === 'center') {
      ctx.strokeStyle = 'white';
      ctx.lineWidth   = 2.5 / globalScale;
      ctx.stroke();
    }

    // Подпись
    const isCenter = node.type === 'center';
    const fontSize = (isCenter ? 13 : 10) / globalScale;
    ctx.font        = `${isCenter ? 600 : 400} ${fontSize}px system-ui, sans-serif`;
    ctx.fillStyle   = '#1E293B';
    ctx.textAlign   = 'center';
    ctx.textBaseline = 'middle';

    const maxLen = isCenter ? 28 : node.type === 'tag' ? 20 : 24;
    const raw    = node.label ?? '';
    const label  = raw.length > maxLen ? raw.slice(0, maxLen - 1) + '…' : raw;
    ctx.fillText(label, node.x, node.y + radius + fontSize * 1.1);
  }, []);

  const handleNodeClick = useCallback((node) => {
    if (node.type === 'resource' && node.contentId) {
      onClose();
      navigate(`/article/${node.contentId}`);
    }
  }, [navigate, onClose]);

  const nodePointerAreaPaint = useCallback((node, color, ctx) => {
    const r = NODE_RADIUS[node.type] ?? 8;
    ctx.fillStyle = color;
    ctx.beginPath();
    ctx.arc(node.x, node.y, r + 4, 0, 2 * Math.PI);
    ctx.fill();
  }, []);

  return (
    <div style={{ position: 'fixed', inset: 0, zIndex: 100 }}>
      {/* Backdrop */}
      <div
        style={{
          position: 'absolute', inset: 0,
          background: 'rgba(15,23,42,0.55)',
          backdropFilter: 'blur(4px)',
          WebkitBackdropFilter: 'blur(4px)',
        }}
        onClick={onClose}
      />

      {/* Panel */}
      <div style={{
        position: 'absolute',
        inset: '3rem 1.5rem 1.5rem',
        borderRadius: '1rem',
        overflow: 'hidden',
        background: 'rgba(255,255,255,0.98)',
        boxShadow: '0 12px 40px rgba(0,0,0,0.18)',
        display: 'flex',
        flexDirection: 'column',
      }}>
        {/* Header */}
        <div style={{
          display: 'flex', alignItems: 'center', justifyContent: 'space-between',
          padding: '1rem 1.5rem',
          borderBottom: '1px solid #E5E7EB',
          flexShrink: 0,
        }}>
          <div>
            <h2 style={{ fontFamily: 'system-ui,sans-serif', fontSize: '1.125rem', fontWeight: 600, color: '#0F172A', margin: 0 }}>
              Граф семантических связей
            </h2>
            {resourceTitle && (
              <p style={{ margin: '0.2rem 0 0', fontSize: '0.8125rem', color: '#64748B' }}>
                {resourceTitle.length > 70 ? resourceTitle.slice(0, 68) + '…' : resourceTitle}
              </p>
            )}
          </div>
          <button
            onClick={onClose}
            style={{ padding: '0.5rem', background: 'none', border: 'none', borderRadius: '0.5rem', cursor: 'pointer', color: '#6B7280', display: 'flex' }}
          >
            <X size={20} />
          </button>
        </div>

        {/* Canvas area */}
        <div ref={containerRef} style={{ flex: 1, position: 'relative', overflow: 'hidden', background: '#F8FAFC' }}>
          {loading && (
            <div style={{ position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
              <p style={{ color: '#64748B', fontSize: '0.9375rem' }}>Загрузка графа…</p>
            </div>
          )}

          {!loading && error && (
            <div style={{ position: 'absolute', inset: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: '2rem' }}>
              <div style={{ textAlign: 'center', maxWidth: '420px' }}>
                <p style={{ fontSize: '2rem', marginBottom: '0.75rem' }}>🔗</p>
                <p style={{ color: '#64748B', fontSize: '0.9375rem', lineHeight: 1.6 }}>{error}</p>
              </div>
            </div>
          )}

          {!loading && !error && graphData.nodes.length > 0 && (
            <ForceGraph2D
              ref={graphRef}
              graphData={graphData}
              width={dims.width}
              height={dims.height}
              backgroundColor="#F8FAFC"
              nodeCanvasObject={paintNode}
              nodeCanvasObjectMode={() => 'replace'}
              nodePointerAreaPaint={nodePointerAreaPaint}
              linkColor={() => '#CBD5E1'}
              linkWidth={1.5}
              linkDirectionalParticles={0}
              enableZoomInteraction
              enablePanInteraction
              cooldownTicks={100}
              onEngineStop={handleEngineStop}
              onNodeClick={handleNodeClick}
              nodeLabel={(node) => node.type === 'resource' ? `${node.label}\n(нажмите для перехода)` : node.label}
            />
          )}
        </div>

        {/* Legend */}
        <div style={{
          padding: '0.625rem 1.5rem',
          borderTop: '1px solid #E5E7EB',
          display: 'flex', gap: '1.5rem', alignItems: 'center',
          flexShrink: 0, background: 'white',
        }}>
          {LEGEND.map(({ color, label }) => (
            <div key={label} style={{ display: 'flex', alignItems: 'center', gap: '0.375rem' }}>
              <div style={{ width: '0.625rem', height: '0.625rem', background: color, borderRadius: '50%', flexShrink: 0 }} />
              <span style={{ fontSize: '0.8125rem', color: '#374151' }}>{label}</span>
            </div>
          ))}
          {graphData.nodes.length > 0 && (
            <span style={{ marginLeft: 'auto', fontSize: '0.75rem', color: '#94A3B8' }}>
              {graphData.nodes.length} узлов · {graphData.links.length} связей
            </span>
          )}
        </div>
      </div>
    </div>
  );
};

export default GraphModal;
