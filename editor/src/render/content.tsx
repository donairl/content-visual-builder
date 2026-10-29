/**
 * Widget markup (schema/README.md §5). The same components render the editor canvas and, in
 * publish mode, the HTML the backend must reproduce — the parity tests compare the two. Editor
 * behaviour is injected through RenderContext so the markup itself never forks.
 */
import type { ElementType, ReactNode } from 'react';
import { propOf } from '@/core/document';
import { EMPTY_SITE, type SiteContext } from '@/core/globals';
import { sanitizeHtml } from '@/core/sanitize';
import { icons, patterns, widgets } from '@/core/schema';
import type { ElementNode, ImageValue, LinkValue, PageDocument } from '@/core/types';
import { isValidLinkUrl, isValidMediaUrl, isValidVideoUrl } from '@/core/validate';
import { embedUrl, parseVideoUrl } from '@/core/video';
import { mergeProps } from './merge';

type Props = Record<string, unknown>;

export interface RenderContext {
  mode: 'publish' | 'editor';
  renderChildren: (ids: string[], parent: ElementNode | null) => ReactNode;
  /** Editor attributes for a node's root element (refs, data attributes, handlers). */
  rootProps?: (node: ElementNode) => Props;
  /** Editor attributes for a container's inner element. */
  innerProps?: (node: ElementNode) => Props;
  /**
   * Editor binding for the element that holds an inline-editable prop. When it returns props, the
   * element is rendered without React children and the binding owns its content.
   */
  editable?: (node: ElementNode, prop: string, kind: 'text' | 'html', value: string) => Props | null;
  placeholder?: (node: ElementNode, kind: 'image' | 'video' | 'container' | 'component') => ReactNode;
  /** Content of a component instance (README §10); nothing when the component is unknown. */
  component?: (node: ElementNode, componentId: number) => ReactNode;
  /**
   * Rendering a component's own nodes: their CSS ids are omitted (a component may appear several
   * times on a page) and nested component instances render nothing.
   */
  inComponent?: boolean;
}

function str(node: ElementNode, key: string): string {
  const value = propOf<unknown>(node, key);
  return typeof value === 'string' ? value : '';
}

function bool(node: ElementNode, key: string): boolean {
  return propOf<unknown>(node, key) === true;
}

/** Enum prop, falling back to the manifest default when the stored value is not allowed. */
function choice(node: ElementNode, key: string): string {
  const spec = widgets[node.type]?.props[key];
  const value = propOf<unknown>(node, key);
  return typeof value === 'string' && spec?.options?.includes(value) ? value : String(spec?.default ?? '');
}

function link(node: ElementNode, key = 'link'): LinkValue | null {
  const value = propOf<Partial<LinkValue> | undefined>(node, key);
  if (!value || typeof value.url !== 'string' || value.url === '' || !isValidLinkUrl(value.url)) return null;
  return { url: value.url, newTab: value.newTab === true, nofollow: value.nofollow === true };
}

export function linkAttributes(value: LinkValue): Props {
  const rel = [...(value.nofollow ? ['nofollow'] : []), ...(value.newTab ? ['noopener', 'noreferrer'] : [])];
  return {
    href: value.url,
    target: value.newTab ? '_blank' : undefined,
    rel: rel.length ? rel.join(' ') : undefined,
  };
}

export function rootClassName(node: ElementNode, modifiers: string[] = []): string {
  const classes = node.advanced?.cssClasses;
  const extra = typeof classes === 'string' && classes !== '' && patterns.cssClasses.test(classes) ? classes : '';
  return ['cvb-el', `cvb-el-${node.id}`, `cvb-${node.type}`, ...modifiers, extra].filter(Boolean).join(' ');
}

function root(node: ElementNode, ctx: RenderContext, modifiers: string[] = []): Props {
  const cssId = node.advanced?.cssId;
  return {
    ...ctx.rootProps?.(node),
    id: !ctx.inComponent && typeof cssId === 'string' && cssId !== '' && patterns.cssId.test(cssId) ? cssId : undefined,
    className: rootClassName(node, modifiers),
  };
}

const escapeAttr = (value: string) =>
  value.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');

function Container({ node, ctx }: { node: ElementNode; ctx: RenderContext }) {
  const Tag = choice(node, 'tag') as ElementType;
  return (
    <Tag {...root(node, ctx, [`cvb-container--${choice(node, 'contentWidth')}`])}>
      <div className="cvb-container__inner" {...ctx.innerProps?.(node)}>
        {ctx.renderChildren(node.children, node)}
        {node.children.length === 0 ? ctx.placeholder?.(node, 'container') : null}
      </div>
    </Tag>
  );
}

function Heading({ node, ctx }: { node: ElementNode; ctx: RenderContext }) {
  const Tag = choice(node, 'tag') as ElementType;
  const text = str(node, 'text');
  const editable = ctx.editable?.(node, 'text', 'text', text) ?? null;
  const href = link(node);
  if (href) {
    return (
      <Tag {...root(node, ctx)}>
        <a {...mergeProps(linkAttributes(href), editable)}>{editable ? null : text}</a>
      </Tag>
    );
  }
  return <Tag {...mergeProps(root(node, ctx), editable)}>{editable ? null : text}</Tag>;
}

function Text({ node, ctx }: { node: ElementNode; ctx: RenderContext }) {
  const html = str(node, 'html');
  const editable = ctx.editable?.(node, 'html', 'html', html) ?? null;
  if (editable) return <div {...mergeProps(root(node, ctx), editable)} />;
  return <div {...root(node, ctx)} dangerouslySetInnerHTML={{ __html: sanitizeHtml(html) }} />;
}

function Button({ node, ctx }: { node: ElementNode; ctx: RenderContext }) {
  const text = str(node, 'text');
  const href = link(node);
  const editable = ctx.editable?.(node, 'text', 'text', text) ?? null;
  const Tag = href ? 'a' : 'span';
  const props = { ...root(node, ctx, [`cvb-button--${choice(node, 'size')}`]), ...(href ? linkAttributes(href) : {}) };
  return <Tag {...mergeProps(props, editable)}>{editable ? null : text}</Tag>;
}

function Image({ node, ctx }: { node: ElementNode; ctx: RenderContext }) {
  const image = propOf<Partial<ImageValue> | undefined>(node, 'image');
  const url = image && typeof image.url === 'string' && image.url !== '' && isValidMediaUrl(image.url) ? image.url : '';
  const sized = typeof image?.width === 'number' && typeof image?.height === 'number';
  const caption = str(node, 'caption');
  const href = link(node);
  const img = url ? (
    <img
      src={url}
      alt={str(node, 'alt')}
      loading="lazy"
      decoding="async"
      width={sized ? image!.width! : undefined}
      height={sized ? image!.height! : undefined}
    />
  ) : null;
  return (
    <figure {...root(node, ctx)}>
      {img ? href ? <a {...linkAttributes(href)}>{img}</a> : img : ctx.placeholder?.(node, 'image')}
      {caption ? <figcaption className="cvb-image__caption">{caption}</figcaption> : null}
    </figure>
  );
}

function Video({ node, ctx }: { node: ElementNode; ctx: RenderContext }) {
  const url = str(node, 'url');
  const source = url && isValidVideoUrl(url) ? parseVideoUrl(url) : null;
  // The canvas never autoplays: a looping, playing video makes the editor unusable.
  const options = {
    autoplay: ctx.mode === 'publish' && bool(node, 'autoplay'),
    muted: bool(node, 'muted'),
    loop: bool(node, 'loop'),
    controls: bool(node, 'controls'),
  };
  if (source?.kind === 'file') {
    // React drops the `muted` attribute when serializing, so the <video> tag is built by hand.
    const flags = [
      options.controls && 'controls',
      options.autoplay && 'autoplay',
      (options.muted || options.autoplay) && 'muted',
      options.loop && 'loop',
    ].filter(Boolean);
    const markup = `<video src="${escapeAttr(source.url)}" preload="metadata" playsinline${flags.map((f) => ` ${f}`).join('')}></video>`;
    return <div {...root(node, ctx)} dangerouslySetInnerHTML={{ __html: markup }} />;
  }
  return (
    <div {...root(node, ctx)}>
      {source ? (
        <iframe
          src={embedUrl(source, options)}
          title={str(node, 'title')}
          loading="lazy"
          allow="autoplay; encrypted-media; picture-in-picture; fullscreen"
          allowFullScreen
          referrerPolicy="strict-origin-when-cross-origin"
        />
      ) : (
        ctx.placeholder?.(node, 'video')
      )}
    </div>
  );
}

function Spacer({ node, ctx }: { node: ElementNode; ctx: RenderContext }) {
  return <div {...root(node, ctx)} aria-hidden="true" />;
}

function Divider({ node, ctx }: { node: ElementNode; ctx: RenderContext }) {
  return <hr {...root(node, ctx)} />;
}

export function IconSvg({ name }: { name: string }) {
  return (
    <svg
      xmlns="http://www.w3.org/2000/svg"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="2"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
      focusable="false"
      dangerouslySetInnerHTML={{ __html: icons[name]?.svg ?? '' }}
    />
  );
}

function Icon({ node, ctx }: { node: ElementNode; ctx: RenderContext }) {
  const name = str(node, 'icon');
  const label = str(node, 'label');
  const href = link(node);
  if (href) {
    return (
      <span {...root(node, ctx)}>
        <a {...linkAttributes(href)} aria-label={label || undefined}>
          <IconSvg name={name} />
        </a>
      </span>
    );
  }
  const a11y = label ? { role: 'img', 'aria-label': label } : { 'aria-hidden': true };
  return (
    <span {...root(node, ctx)} {...a11y}>
      <IconSvg name={name} />
    </span>
  );
}

function Component({ node, ctx }: { node: ElementNode; ctx: RenderContext }) {
  if (ctx.inComponent) return null;
  const id = propOf<unknown>(node, 'componentId');
  const content = typeof id === 'number' ? ctx.component?.(node, id) : null;
  return <div {...root(node, ctx)}>{content ?? ctx.placeholder?.(node, 'component')}</div>;
}

const COMPONENTS: Record<string, (props: { node: ElementNode; ctx: RenderContext }) => ReactNode> = {
  container: Container,
  heading: Heading,
  text: Text,
  button: Button,
  image: Image,
  video: Video,
  spacer: Spacer,
  divider: Divider,
  icon: Icon,
  component: Component,
};

export function NodeContent({ node, ctx }: { node: ElementNode; ctx: RenderContext }) {
  const Component = COMPONENTS[node.type];
  return Component ? <Component node={node} ctx={ctx} /> : null;
}

/**
 * Publish-mode rendering of a whole document (the body fragment inside `.cvb-root`). Component
 * instances render the component's published document from `site`.
 */
export function PublishedTree({
  doc,
  site = EMPTY_SITE,
  inComponent = false,
  mode = 'publish',
}: {
  doc: PageDocument;
  site?: SiteContext;
  inComponent?: boolean;
  /** `editor` renders the same static markup for the canvas (no autoplaying video). */
  mode?: RenderContext['mode'];
}) {
  const ctx: RenderContext = {
    mode,
    inComponent,
    renderChildren: (ids) =>
      ids.map((id) => {
        const node = doc.nodes[id];
        return node ? <NodeContent key={id} node={node} ctx={ctx} /> : null;
      }),
    component: (_node, componentId) => {
      const component = site.components.get(componentId);
      return component ? <PublishedTree doc={component} site={site} inComponent mode={mode} /> : null;
    },
  };
  return <>{ctx.renderChildren(doc.rootIds, null)}</>;
}
