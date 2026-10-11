import { BaseComponent } from './BaseComponent';

class LifecycleProbe extends BaseComponent {
  private dependency: string;
  public observedDependency = '';
  public destroyCount = 0;

  constructor(element: HTMLElement) {
    super({ element });
    this.dependency = 'ready';
    this.init();
  }

  protected onInit(): void {
    this.observedDependency = this.dependency;
  }

  protected onMount(): void {}
  protected onUnmount(): void {}
  protected onDestroy(): void {
    this.destroyCount += 1;
  }
  protected onRender(): void {}
  protected bindEvents(): void {}
  protected onStateChange(): void {}
}

describe('BaseComponent lifecycle', () => {
  it('lets subclasses initialize their fields before lifecycle hooks run', () => {
    const component = new LifecycleProbe(document.createElement('button'));

    expect(component.observedDependency).toBe('ready');
    component.destroy();
    expect(component.destroyCount).toBe(1);
  });
});
